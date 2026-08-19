<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;
use App\Services\Audit;
use App\Services\Otp;

/**
 * Web sign-in for Admin/Supervisor and Branch Manager accounts.
 *
 * BC Supervisors authenticate through the Android API instead; if one signs in
 * here they are told so rather than being shown a half-working web UI.
 */
final class AuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        $this->view('auth.login', [
            'title' => 'Sign in',
            'otpEnabled' => Settings::bool('otp_web_login', false),
        ], 'layouts.auth');
    }

    public function login(Request $request): void
    {
        $data = $this->validate($request, [
            'login' => 'required|max:190',
            'password' => 'required|max:255',
        ], [], '/login');

        $login = (string) $data['login'];
        $ipKey = 'login:ip:' . $request->ip();
        $userKey = 'login:user:' . strtolower($login);

        $maxAttempts = (int) Config::get('security.login_max_attempts', 5);
        $decay = ((int) Config::get('security.login_decay_minutes', 15)) * 60;
        // The IP ceiling is separate and much higher: a branch office shares one
        // connection, so the username limit is what guards an account and this only
        // stops a flood from a single address.
        $ipMaxAttempts = max($maxAttempts, (int) Config::get('security.login_ip_max_attempts', 50));

        // Throttle by both address and account so one does not mask the other.
        foreach ([$userKey => $maxAttempts, $ipKey => $ipMaxAttempts] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $seconds = RateLimiter::availableIn($key);

                Audit::log(Audit::LOGIN_FAILED, [
                    'description' => sprintf('Sign-in throttled for "%s" from %s.', $login, $request->ip()),
                ]);

                $this->error(sprintf(
                    'Too many sign-in attempts. Please wait %d minute(s) and try again.',
                    max(1, (int) ceil($seconds / 60))
                ));
                $this->redirect('/login');

                return;
            }
        }

        $result = Auth::verify($login, (string) $data['password']);

        if (!$result['ok']) {
            RateLimiter::hit($ipKey, $decay);
            RateLimiter::hit($userKey, $decay);

            $message = match ($result['reason']) {
                'locked' => 'This account is temporarily locked after repeated failed attempts. Try again later or ask your Admin/Supervisor to reset it.',
                'inactive' => 'This account is not active. Contact your Admin/Supervisor.',
                default => 'Those credentials do not match our records.',
            };

            Audit::log(Audit::LOGIN_FAILED, [
                'user_id' => $result['user'] === null ? null : (int) $result['user']['id'],
                'description' => sprintf('Failed sign-in for "%s" (%s) from %s.', $login, $result['reason'], $request->ip()),
            ]);

            $this->error($message);
            Session::flashInput(['login' => $login]);
            $this->redirect('/login');

            return;
        }

        $user = $result['user'];

        RateLimiter::clear($ipKey);
        RateLimiter::clear($userKey);

        // Field staff belong in the Android app.
        if ((string) $user['role'] === Auth::ROLE_BC) {
            Audit::log(Audit::LOGIN_FAILED, [
                'user_id' => (int) $user['id'],
                'description' => 'BC Supervisor attempted to sign in to the web portal.',
            ]);

            $this->error('BC Supervisor accounts sign in through the LRMS Android app, not the web portal.');
            $this->redirect('/app-only');

            return;
        }

        if (Settings::bool('otp_web_login', false)) {
            Auth::setPending((int) $user['id']);
            $issued = Otp::issue($user, Otp::PURPOSE_LOGIN);

            Session::put('_otp_hint', $issued['message']);

            if ($issued['debug_code'] !== null) {
                $this->info('Development mode: your code is ' . $issued['debug_code']);
            }

            $this->redirect('/login/verify');

            return;
        }

        $this->completeLogin($user);
    }

    public function showVerify(Request $request): void
    {
        if (Auth::pendingId() === null) {
            $this->redirect('/login');

            return;
        }

        $this->view('auth.verify', [
            'title' => 'Verify sign-in',
            'hint' => Session::get('_otp_hint', 'Enter the verification code that was sent to you.'),
        ], 'layouts.auth');
    }

    public function verify(Request $request): void
    {
        $pendingId = Auth::pendingId();

        if ($pendingId === null) {
            $this->redirect('/login');

            return;
        }

        $data = $this->validate($request, ['code' => 'required|max:12'], [], '/login/verify');

        RateLimiter::guard(
            'otp:' . $pendingId,
            10,
            600,
            'Too many verification attempts. Please request a new code shortly.'
        );

        $check = Otp::verify($pendingId, (string) $data['code'], Otp::PURPOSE_LOGIN);

        if (!$check['ok']) {
            $this->error($check['message']);
            $this->redirect('/login/verify');

            return;
        }

        $user = Auth::findById($pendingId);

        if ($user === null || (string) $user['status'] !== 'active') {
            Auth::clearPending();
            $this->error('That account is no longer active.');
            $this->redirect('/login');

            return;
        }

        $this->completeLogin($user);
    }

    public function resend(Request $request): void
    {
        $pendingId = Auth::pendingId();

        if ($pendingId === null) {
            $this->redirect('/login');

            return;
        }

        $user = Auth::findById($pendingId);

        if ($user === null) {
            $this->redirect('/login');

            return;
        }

        $issued = Otp::issue($user, Otp::PURPOSE_LOGIN);
        Session::put('_otp_hint', $issued['message']);

        if ($issued['debug_code'] !== null) {
            $this->info('Development mode: your code is ' . $issued['debug_code']);
        }

        $this->info($issued['message']);
        $this->redirect('/login/verify');
    }

    private function completeLogin(array $user): void
    {
        Auth::login($user);

        Audit::log(Audit::LOGIN, [
            'user_id' => (int) $user['id'],
            'entity_type' => 'user',
            'entity_id' => (int) $user['id'],
            'description' => sprintf('%s signed in.', $user['name']),
        ]);

        $intended = Session::get('_intended_url');
        Session::forget('_intended_url');
        Session::forget('_otp_hint');

        if (Auth::mustChangePassword()) {
            $this->info('Please set a new password before continuing.');
            $this->redirect('/password/change');

            return;
        }

        $this->success('Welcome back, ' . $user['name'] . '.');

        // Only follow an intended URL inside this application.
        if (is_string($intended) && str_starts_with($intended, base_url())) {
            $this->redirect($intended);

            return;
        }

        $this->redirect(Auth::homeFor((string) $user['role']));
    }

    public function logout(Request $request): void
    {
        $user = auth_user();

        if ($user !== null) {
            Audit::log(Audit::LOGOUT, [
                'user_id' => (int) $user['id'],
                'description' => sprintf('%s signed out.', $user['name']),
            ]);
        }

        Auth::logout();
        Session::start();
        $this->info('You have been signed out.');
        $this->redirect('/login');
    }

    /* ------------------------------------------------------------------ */
    /* Password management                                                */
    /* ------------------------------------------------------------------ */

    public function showChangePassword(Request $request): void
    {
        $this->view('auth.password', [
            'title' => 'Change password',
            'forced' => Auth::mustChangePassword(),
        ], Auth::mustChangePassword() ? 'layouts.auth' : 'layouts.app');
    }

    public function changePassword(Request $request): void
    {
        $minLength = (int) Config::get('security.password_min_length', 8);

        $data = $this->validate($request, [
            'current_password' => 'required',
            'password' => 'required|password|min:' . $minLength . '|confirmed',
        ], [], '/password/change');

        $user = auth_user();

        if ($user === null) {
            $this->redirect('/login');

            return;
        }

        if (!password_verify((string) $data['current_password'], (string) $user['password'])) {
            $this->error('Your current password is not correct.');
            $this->redirect('/password/change');

            return;
        }

        if (password_verify((string) $data['password'], (string) $user['password'])) {
            $this->error('Choose a password you have not used before.');
            $this->redirect('/password/change');

            return;
        }

        Database::update('users', [
            'password' => Auth::hashPassword((string) $data['password']),
            'must_change_password' => 0,
            'password_changed_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) $user['id']]);

        Auth::refresh();

        Audit::log(Audit::PASSWORD_CHANGED, [
            'user_id' => (int) $user['id'],
            'entity_type' => 'user',
            'entity_id' => (int) $user['id'],
            'description' => 'Password changed by the account owner.',
        ]);

        $this->success('Your password has been updated.');
        $this->redirect(Auth::homeFor());
    }

    /**
     * Landing page for BC Supervisors who reach the web portal by mistake.
     */
    public function appOnly(Request $request): void
    {
        $this->view('auth.app-only', ['title' => 'Use the LRMS Android app'], 'layouts.auth');
    }

    public function health(Request $request): void
    {
        $healthy = true;
        $checks = [];

        try {
            $checks['database'] = Database::isConnected() ? 'ok' : 'unavailable';
            $healthy = $healthy && $checks['database'] === 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'unavailable';
            $healthy = false;
        }

        $checks['storage'] = is_writable(storage_path('logs')) ? 'ok' : 'not writable';
        $healthy = $healthy && $checks['storage'] === 'ok';

        $this->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => app_name(),
            'time' => now(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }
}
