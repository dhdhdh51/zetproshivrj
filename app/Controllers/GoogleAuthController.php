<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;
use App\Models\ActivityLog;
use App\Models\User;

/**
 * Google Sign-In (OAuth 2.0 authorization code flow, no external SDK required).
 */
final class GoogleAuthController extends Controller
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function start(Request $request): void
    {
        if (!$this->isConfigured()) {
            $this->error('Google Login is not configured on this installation.');
            $this->redirect('/login');

            return;
        }

        $state = bin2hex(random_bytes(16));
        Session::put('_google_state', $state);

        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
            'include_granted_scopes' => 'true',
        ]);

        $this->redirect(self::AUTH_URL . '?' . $query);
    }

    public function callback(Request $request): void
    {
        if (!$this->isConfigured()) {
            $this->error('Google Login is not configured on this installation.');
            $this->redirect('/login');

            return;
        }

        $error = (string) $request->query('error', '');
        if ($error !== '') {
            $this->error('Google sign-in was cancelled.');
            $this->redirect('/login');

            return;
        }

        $state = (string) $request->query('state', '');
        $expected = (string) Session::get('_google_state', '');
        Session::forget('_google_state');

        if ($expected === '' || !hash_equals($expected, $state)) {
            $this->error('The Google sign-in request expired. Please try again.');
            $this->redirect('/login');

            return;
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            $this->error('Google did not return an authorisation code.');
            $this->redirect('/login');

            return;
        }

        $token = $this->exchangeCode($code);

        if ($token === null) {
            $this->error('We could not complete the Google sign-in. Please try again or use your email and password.');
            $this->redirect('/login');

            return;
        }

        $profile = $this->fetchUserInfo($token);

        if ($profile === null || empty($profile['email'])) {
            $this->error('Google did not share an email address with us.');
            $this->redirect('/login');

            return;
        }

        $this->signIn($profile);
    }

    /* ------------------------------------------------------------------ */

    private function signIn(array $profile): void
    {
        $users = new User();
        $email = strtolower((string) $profile['email']);
        $googleId = (string) ($profile['sub'] ?? '');
        $name = trim((string) ($profile['name'] ?? '')) !== '' ? (string) $profile['name'] : strstr($email, '@', true);

        $user = $googleId !== '' ? $users->findByGoogleId($googleId) : null;
        $user ??= $users->findByEmail($email);

        if ($user === null) {
            if (!Settings::bool('registration_enabled', true)) {
                $this->error('New registrations are currently closed.');
                $this->redirect('/login');

                return;
            }

            $userId = $users->create([
                'name' => (string) $name,
                'email' => $email,
                'password' => null,
                'google_id' => $googleId !== '' ? $googleId : null,
                'avatar' => (string) ($profile['picture'] ?? '') ?: null,
                'role' => 'user',
                'status' => 'active',
                // Google has already verified the address.
                'email_verified_at' => now(),
            ]);

            $user = $users->find($userId) ?? [];
            ActivityLog::record($userId, 'auth.registered_google', $email);

            Auth::login($user);
            $this->success('Welcome to ' . app_name() . '!');
            $this->redirect('/profile/business?onboarding=1');

            return;
        }

        if ((string) $user['status'] !== 'active') {
            $this->error('Your account has been deactivated. Please contact support.');
            $this->redirect('/login');

            return;
        }

        $updates = [];

        if (empty($user['google_id']) && $googleId !== '') {
            $updates['google_id'] = $googleId;
        }

        if (empty($user['email_verified_at'])) {
            $updates['email_verified_at'] = now();
        }

        if (empty($user['avatar']) && !empty($profile['picture'])) {
            $updates['avatar'] = (string) $profile['picture'];
        }

        if ($updates !== []) {
            $users->updateById((int) $user['id'], $updates);
            $user = $users->find((int) $user['id']) ?? $user;
        }

        Auth::login($user);
        ActivityLog::record((int) $user['id'], 'auth.login_google', $email);

        $intended = Session::get('_intended_url');
        Session::forget('_intended_url');

        if (is_string($intended) && $intended !== '' && !str_contains($intended, '/login')) {
            $this->redirect($intended);

            return;
        }

        $this->redirect(Auth::isAdmin() ? '/admin' : '/dashboard');
    }

    private function exchangeCode(string $code): ?string
    {
        $response = $this->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => (string) Config::get('google.client_secret', ''),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if ($response === null) {
            return null;
        }

        $token = (string) ($response['access_token'] ?? '');

        return $token === '' ? null : $token;
    }

    private function fetchUserInfo(string $accessToken): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init(self::USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status !== 200) {
            Logger::error('Google userinfo failed', ['status' => $status, 'error' => $error]);

            return null;
        }

        $body = json_decode((string) $raw, true);

        return is_array($body) ? $body : null;
    }

    private function post(string $url, array $fields): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            Logger::error('Google token exchange failed', ['status' => $status, 'error' => $error, 'body' => (string) $raw]);

            return null;
        }

        $body = json_decode((string) $raw, true);

        return is_array($body) ? $body : null;
    }

    private function isConfigured(): bool
    {
        return $this->clientId() !== '' && (string) Config::get('google.client_secret', '') !== '';
    }

    private function clientId(): string
    {
        return (string) Config::get('google.client_id', '');
    }

    private function redirectUri(): string
    {
        $configured = (string) Config::get('google.redirect_uri', '');

        if ($configured !== '' && !str_contains($configured, 'yourdomain.com')) {
            return $configured;
        }

        return url('auth/google/callback');
    }
}
