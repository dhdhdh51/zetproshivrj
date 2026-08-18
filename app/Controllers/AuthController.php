<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;
use App\Models\ActivityLog;
use App\Models\EmailVerification;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\MailService;
use App\Validators\AuthRules;

final class AuthController extends Controller
{
    private User $users;

    public function __construct()
    {
        $this->users = new User();
    }

    /* ================================================================== */
    /* Registration                                                        */
    /* ================================================================== */

    public function showRegister(Request $request): void
    {
        if (!Settings::bool('registration_enabled', true)) {
            $this->view('auth.closed', [
                'title' => 'Registration closed',
            ], 'layouts.auth');

            return;
        }

        $this->view('auth.register', [
            'title' => 'Create your account',
            'meta_description' => 'Create a free ' . app_name() . ' account and start generating professional business documents with AI.',
            'google_enabled' => $this->googleEnabled(),
        ], 'layouts.auth');
    }

    public function register(Request $request): void
    {
        if (!Settings::bool('registration_enabled', true)) {
            throw new HttpException(403, 'New registrations are currently closed.');
        }

        RateLimiter::guard('register:' . $request->ip(), 10, 3600, 'Too many sign-up attempts. Please try again later.');

        $data = $this->validate(
            $request,
            AuthRules::register(),
            AuthRules::registerMessages(),
            '/register'
        );

        $email = strtolower((string) $data['email']);
        $mailer = new MailService();
        $verificationRequired = $mailer->isConfigured();

        $userId = $this->users->create([
            'name' => (string) $data['name'],
            'email' => $email,
            'password' => Auth::hashPassword((string) $data['password']),
            'role' => 'user',
            'status' => 'active',
            // Without SMTP we cannot deliver a verification email, so the account
            // is trusted immediately (verification can be enforced later in Admin).
            'email_verified_at' => $verificationRequired ? null : now(),
        ]);

        $user = $this->users->find($userId) ?? [];
        ActivityLog::record($userId, 'auth.registered', $email);

        if ($verificationRequired) {
            $token = (new EmailVerification())->issue($userId);
            $result = $mailer->sendVerification($user, $token);

            $this->success($result['success']
                ? 'Account created. We sent a confirmation link to ' . $email . '.'
                : 'Account created, but the confirmation email could not be sent: ' . $result['message']);
        } else {
            $this->success('Welcome to ' . app_name() . '! Your account is ready.');
        }

        Auth::login($user);
        Session::clearOld();

        $this->redirect('/profile/business?onboarding=1');
    }

    /* ================================================================== */
    /* Login / logout                                                      */
    /* ================================================================== */

    public function showLogin(Request $request): void
    {
        $this->view('auth.login', [
            'title' => 'Sign in',
            'meta_description' => 'Sign in to ' . app_name() . ' to create AI-powered quotations, invoices and proposals.',
            'google_enabled' => $this->googleEnabled(),
        ], 'layouts.auth');
    }

    public function login(Request $request): void
    {
        $email = strtolower((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $remember = $request->boolean('remember');

        $maxAttempts = (int) Config::get('security.login_max_attempts', 5);
        $decay = (int) Config::get('security.login_decay_minutes', 15) * 60;
        $key = 'login:' . $request->ip() . ':' . sha1($email);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new HttpException(429, sprintf(
                'Too many failed sign-in attempts. Please try again in %d minutes.',
                (int) ceil(RateLimiter::availableIn($key) / 60)
            ));
        }

        $this->validate($request, AuthRules::login(), [], '/login');

        if (!Auth::attempt($email, $password, $remember)) {
            RateLimiter::hit($key, $decay);
            ActivityLog::record(null, 'auth.login_failed', $email);

            Session::flashInput(['email' => $email]);

            if (Session::errors() === [] && $this->noFlashYet()) {
                $this->error('Those credentials do not match our records.');
            }

            $this->redirect('/login');

            return;
        }

        RateLimiter::clear($key);
        ActivityLog::record(Auth::id(), 'auth.login', $email);

        $intended = Session::get('_intended_url');
        Session::forget('_intended_url');

        if (is_string($intended) && $intended !== '' && !str_contains($intended, '/login')) {
            $this->redirect($intended);

            return;
        }

        $this->redirect(Auth::isAdmin() ? '/admin' : '/dashboard');
    }

    public function logout(Request $request): void
    {
        ActivityLog::record(Auth::id(), 'auth.logout');
        Auth::logout();
        Session::start();
        $this->success('You have been signed out.');
        $this->redirect('/');
    }

    /* ================================================================== */
    /* Forgot / reset password                                             */
    /* ================================================================== */

    public function showForgot(Request $request): void
    {
        $this->view('auth.forgot', ['title' => 'Reset your password'], 'layouts.auth');
    }

    public function sendReset(Request $request): void
    {
        RateLimiter::guard('forgot:' . $request->ip(), 8, 1800, 'Too many reset requests. Please try again later.');

        $data = $this->validate($request, ['email' => 'required|email'], [], '/password/forgot');
        $email = strtolower((string) $data['email']);
        $user = $this->users->findByEmail($email);

        // Always respond the same way so accounts cannot be enumerated.
        $generic = 'If an account exists for ' . $email . ', a password reset link is on its way.';

        if ($user === null) {
            $this->success($generic);
            $this->redirect('/login');

            return;
        }

        $token = (new PasswordReset())->issue($email);
        $result = (new MailService())->sendPasswordReset($user, $token);

        ActivityLog::record((int) $user['id'], 'auth.password_reset_requested', $email);

        if (!$result['success'] && (bool) Config::get('app.debug', false)) {
            $this->info('SMTP is unavailable (' . $result['message'] . '). Debug mode reset link: ' . url('password/reset/' . $token));
        } else {
            $this->success($generic);
        }

        $this->redirect('/login');
    }

    public function showReset(Request $request): void
    {
        $token = (string) $request->param('token');
        $reset = (new PasswordReset())->findValid($token);

        if ($reset === null) {
            $this->error('That password reset link is invalid or has expired. Please request a new one.');
            $this->redirect('/password/forgot');

            return;
        }

        $this->view('auth.reset', [
            'title' => 'Choose a new password',
            'token' => $token,
            'email' => (string) $reset['email'],
        ], 'layouts.auth');
    }

    public function resetPassword(Request $request): void
    {
        $token = (string) $request->input('token', '');
        $resets = new PasswordReset();
        $reset = $resets->findValid($token);

        if ($reset === null) {
            $this->error('That password reset link is invalid or has expired.');
            $this->redirect('/password/forgot');

            return;
        }

        $data = $this->validate($request, AuthRules::newPassword(), [], '/password/reset/' . $token);

        $user = $this->users->findByEmail((string) $reset['email']);

        if ($user === null) {
            $this->error('We could not find that account.');
            $this->redirect('/login');

            return;
        }

        $this->users->updatePassword((int) $user['id'], (string) $data['password']);
        $resets->consume((int) $reset['id']);

        ActivityLog::record((int) $user['id'], 'auth.password_reset', (string) $user['email']);

        $this->success('Your password has been updated. You can sign in now.');
        $this->redirect('/login');
    }

    /* ================================================================== */
    /* Email verification                                                  */
    /* ================================================================== */

    public function verifyNotice(Request $request): void
    {
        $this->view('auth.verify', [
            'title' => 'Confirm your email address',
            'user' => Auth::user(),
        ], 'layouts.auth');
    }

    public function verify(Request $request): void
    {
        $verifications = new EmailVerification();
        $record = $verifications->findValid((string) $request->param('token'));

        if ($record === null) {
            $this->error('That confirmation link is invalid or has expired. Please request a new one.');
            $this->redirect(Auth::check() ? '/email/verify' : '/login');

            return;
        }

        $verifications->consume((int) $record['id']);
        $this->users->markVerified((int) $record['user_id']);
        Auth::refresh();

        ActivityLog::record((int) $record['user_id'], 'auth.email_verified');

        $this->success('Thank you — your email address is confirmed.');
        $this->redirect(Auth::check() ? '/dashboard' : '/login');
    }

    public function resendVerification(Request $request): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->redirect('/login');

            return;
        }

        if (!empty($user['email_verified_at'])) {
            $this->info('Your email address is already confirmed.');
            $this->redirect('/dashboard');

            return;
        }

        RateLimiter::guard('verify:' . (int) $user['id'], 5, 900, 'Please wait a few minutes before requesting another email.');

        $token = (new EmailVerification())->issue((int) $user['id']);
        $result = (new MailService())->sendVerification($user, $token);

        if ($result['success']) {
            $this->success('Confirmation email sent to ' . (string) $user['email'] . '.');
        } elseif ((bool) Config::get('app.debug', false)) {
            $this->info('SMTP unavailable (' . $result['message'] . '). Debug verification link: ' . url('email/verify/' . $token));
        } else {
            $this->error('We could not send the confirmation email: ' . $result['message']);
        }

        $this->redirect('/email/verify');
    }

    /* ================================================================== */
    /* Helpers                                                             */
    /* ================================================================== */

    private function googleEnabled(): bool
    {
        return (string) Config::get('google.client_id', '') !== ''
            && (string) Config::get('google.client_secret', '') !== '';
    }

    private function noFlashYet(): bool
    {
        return ($_SESSION['_flash'] ?? []) === [];
    }
}
