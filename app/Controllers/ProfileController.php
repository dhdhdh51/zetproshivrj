<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\BusinessProfile;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\UploadService;
use App\Services\UsageService;
use App\Validators\AuthRules;

final class ProfileController extends Controller
{
    private BusinessProfile $profiles;

    public function __construct()
    {
        $this->profiles = new BusinessProfile();
    }

    /* ================================================================== */
    /* Business profile                                                    */
    /* ================================================================== */

    public function business(Request $request): void
    {
        $userId = (int) Auth::id();
        $profile = $this->profiles->forUserOrEmpty($userId);

        $this->view('profile.business', [
            'title' => 'Business profile',
            'profile' => $profile,
            'onboarding' => $request->query('onboarding') === '1',
            'templates' => (new DocumentTemplate())->active(),
            'all_templates' => (new UsageService())->canUseAllTemplates($userId),
            'logo_url' => empty($profile['logo_path']) ? null : url('media/logo/' . $profile['logo_path']),
        ]);
    }

    public function saveBusiness(Request $request): void
    {
        $userId = (int) Auth::id();

        $data = $this->validate($request, AuthRules::businessProfile(), [], '/profile/business');

        $currency = strtoupper((string) ($data['default_currency'] ?? 'INR'));
        $data['default_currency'] = array_key_exists($currency, currencies()) ? $currency : 'INR';

        $templates = new DocumentTemplate();
        $template = (string) ($data['default_template'] ?? '');
        $data['default_template'] = $templates->findBySlug($template) !== null ? $template : $templates->defaultSlug();

        $website = trim((string) ($data['website'] ?? ''));
        if ($website !== '' && !str_starts_with($website, 'http://') && !str_starts_with($website, 'https://')) {
            $website = 'https://' . $website;
        }
        $data['website'] = $website !== '' ? $website : null;

        // Logo upload (optional)
        $file = $request->file('logo');

        if ($file !== null) {
            $uploads = new UploadService();
            $result = $uploads->storeLogo($file);

            if (!$result['success']) {
                $this->error($result['error'] ?? 'The logo could not be uploaded.');
                $this->redirect('/profile/business');

                return;
            }

            $existing = $this->profiles->forUser($userId);
            if ($existing !== null && !empty($existing['logo_path'])) {
                $uploads->delete((string) $existing['logo_path']);
            }

            $data['logo_path'] = $result['filename'];
        }

        $this->profiles->saveForUser($userId, $data);
        ActivityLog::record($userId, 'profile.updated', (string) $data['business_name']);

        $this->success('Business profile saved.');

        if ($request->boolean('onboarding')) {
            $this->redirect('/dashboard');

            return;
        }

        $this->redirect('/profile/business');
    }

    public function deleteLogo(Request $request): void
    {
        $userId = (int) Auth::id();
        $profile = $this->profiles->forUser($userId);

        if ($profile !== null && !empty($profile['logo_path'])) {
            (new UploadService())->delete((string) $profile['logo_path']);
            $this->profiles->updateById((int) $profile['id'], ['logo_path' => null]);
            $this->success('Logo removed.');
        }

        $this->redirect('/profile/business');
    }

    /**
     * Stream a stored logo. Filenames are random 32-char hashes, so they are
     * unguessable and safe to expose on public share pages.
     */
    public function logo(Request $request): void
    {
        $file = basename((string) $request->param('file'));

        if (preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|webp)$/i', $file) !== 1) {
            throw new HttpException(404, 'Image not found.');
        }

        $uploads = new UploadService();
        $path = $uploads->path($file);

        if ($path === null) {
            throw new HttpException(404, 'Image not found.');
        }

        header('Cache-Control: private, max-age=86400');
        Response::inline($path, $file, $uploads->mimeFor($file));
    }

    /* ================================================================== */
    /* Account                                                             */
    /* ================================================================== */

    public function account(Request $request): void
    {
        $userId = (int) Auth::id();

        $this->view('profile.account', [
            'title' => 'Account settings',
            'user' => Auth::user(),
            'summary' => (new UsageService())->summary($userId),
        ]);
    }

    public function updateAccount(Request $request): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->redirect('/login');

            return;
        }

        $userId = (int) $user['id'];

        $data = $this->validate(
            $request,
            AuthRules::account($userId),
            ['email.unique' => 'Another account already uses that email address.'],
            '/profile/account'
        );

        $users = new User();
        $emailChanged = strtolower((string) $data['email']) !== strtolower((string) $user['email']);

        $updates = [
            'name' => (string) $data['name'],
            'email' => strtolower((string) $data['email']),
        ];

        if ($emailChanged) {
            $updates['email_verified_at'] = null;
        }

        $users->updateById($userId, $updates);
        Auth::refresh();

        ActivityLog::record($userId, 'account.updated');
        $this->success($emailChanged
            ? 'Account updated. Please confirm your new email address.'
            : 'Account updated.');

        $this->redirect('/profile/account');
    }

    public function updatePassword(Request $request): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->redirect('/login');

            return;
        }

        $data = $this->validate($request, [
            'current_password' => empty($user['password']) ? 'nullable' : 'required',
            'password' => 'required|password|confirmed',
        ], [], '/profile/account');

        if (!empty($user['password'])
            && !password_verify((string) $data['current_password'], (string) $user['password'])) {
            $this->error('Your current password is incorrect.');
            $this->redirect('/profile/account');

            return;
        }

        (new User())->updatePassword((int) $user['id'], (string) $data['password']);
        ActivityLog::record((int) $user['id'], 'account.password_changed');

        $this->success('Password updated.');
        $this->redirect('/profile/account');
    }
}
