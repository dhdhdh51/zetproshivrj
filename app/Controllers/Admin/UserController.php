<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\AiGeneration;
use App\Models\BusinessProfile;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\UsageService;

final class UserController extends Controller
{
    private User $users;

    public function __construct()
    {
        $this->users = new User();
    }

    public function index(Request $request): void
    {
        $this->view('admin.users.index', [
            'title' => 'Users',
            'search' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'role' => (string) $request->query('role', ''),
            'users' => $this->users->paginateForAdmin(
                (string) $request->query('q', ''),
                (string) $request->query('status', ''),
                (string) $request->query('role', ''),
                $request->integer('page', 1),
                20
            ),
            'stats' => $this->users->statistics(),
        ], 'layouts.admin');
    }

    public function show(Request $request): void
    {
        $user = $this->users->findOrFail($request->paramInt('id'));
        $userId = (int) $user['id'];

        // Passwords are never exposed to the admin UI.
        unset($user['password'], $user['remember_token']);

        $this->view('admin.users.show', [
            'title' => (string) $user['name'],
            'user' => $user,
            'profile' => (new BusinessProfile())->forUser($userId),
            'summary' => (new UsageService())->summary($userId),
            'documents' => (new Document())->recentForUser($userId, 10),
            'documents_count' => (new Document())->count(['user_id' => $userId]),
            'ai_recent' => (new AiGeneration())->recentForUser($userId, 10),
            'payments' => (new Payment())->forUser($userId, 10),
            'subscriptions' => (new Subscription())->historyForUser($userId, 10),
            'plans' => (new Plan())->allOrdered(),
            'activity' => (new \App\Models\ActivityLog())->forUser($userId, 15),
        ], 'layouts.admin');
    }

    public function toggleStatus(Request $request): void
    {
        $user = $this->users->findOrFail($request->paramInt('id'));

        if ((int) $user['id'] === (int) Auth::id()) {
            throw new HttpException(403, 'You cannot deactivate your own account.');
        }

        $status = (string) $user['status'] === 'active' ? 'inactive' : 'active';
        $this->users->updateById((int) $user['id'], ['status' => $status, 'remember_token' => null]);

        ActivityLog::record(Auth::id(), 'admin.user_status', (string) $user['email'] . ' > ' . $status, 'user', (int) $user['id']);

        $this->success(sprintf('%s is now %s.', (string) $user['name'], $status));
        $this->back('/admin/users');
    }

    public function updateRole(Request $request): void
    {
        $user = $this->users->findOrFail($request->paramInt('id'));
        $role = (string) $request->input('role', 'user') === 'admin' ? 'admin' : 'user';

        if ((int) $user['id'] === (int) Auth::id() && $role !== 'admin') {
            throw new HttpException(403, 'You cannot remove your own administrator access.');
        }

        $this->users->updateById((int) $user['id'], ['role' => $role]);
        ActivityLog::record(Auth::id(), 'admin.user_role', (string) $user['email'] . ' > ' . $role, 'user', (int) $user['id']);

        $this->success(sprintf('%s is now %s.', (string) $user['name'], $role === 'admin' ? 'an administrator' : 'a standard user'));
        $this->back('/admin/users/' . (int) $user['id']);
    }

    /**
     * Manually grant a plan (useful for support, refunds and offline payments).
     */
    public function assignPlan(Request $request): void
    {
        $user = $this->users->findOrFail($request->paramInt('id'));
        $planId = $request->integer('plan_id', 0);
        $months = max(1, min(24, $request->integer('months', 1)));

        $plan = (new Plan())->findPlan($planId);

        if ($plan === null) {
            $this->error('That plan does not exist.');
            $this->back('/admin/users/' . (int) $user['id']);

            return;
        }

        (new Subscription())->activate((int) $user['id'], $planId, $months);

        ActivityLog::record(
            Auth::id(),
            'admin.plan_assigned',
            (string) $user['email'] . ' > ' . (string) $plan['name'] . ' (' . $months . 'm)',
            'user',
            (int) $user['id']
        );

        $this->success(sprintf('%s is now on the %s plan for %d month(s).', (string) $user['name'], (string) $plan['name'], $months));
        $this->back('/admin/users/' . (int) $user['id']);
    }

    public function cancelSubscription(Request $request): void
    {
        $user = $this->users->findOrFail($request->paramInt('id'));
        $subscriptions = new Subscription();
        $active = $subscriptions->activeForUser((int) $user['id']);

        if ($active === null) {
            $this->info('That user has no active paid subscription.');
            $this->back('/admin/users/' . (int) $user['id']);

            return;
        }

        $subscriptions->cancel((int) $active['id']);
        ActivityLog::record(Auth::id(), 'admin.subscription_cancelled', (string) $user['email'], 'user', (int) $user['id']);

        $this->success('Subscription cancelled — the account is back on the Free plan.');
        $this->back('/admin/users/' . (int) $user['id']);
    }
}
