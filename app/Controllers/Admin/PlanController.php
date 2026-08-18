<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Subscription;

final class PlanController extends Controller
{
    private Plan $plans;

    public function __construct()
    {
        $this->plans = new Plan();
    }

    public function index(Request $request): void
    {
        $this->view('admin.plans.index', [
            'title' => 'Plans',
            'plans' => $this->plans->allOrdered(),
            'active_subscriptions' => (new Subscription())->activeCount(),
        ], 'layouts.admin');
    }

    public function update(Request $request): void
    {
        $plan = $this->plans->findPlan($request->paramInt('id'));

        if ($plan === null) {
            $this->error('Plan not found.');
            $this->redirect('/admin/plans');

            return;
        }

        $data = $this->validate($request, [
            'name' => 'required|max:60',
            'description' => 'nullable|max:255',
            'price' => 'required|numeric|min:0',
            'currency' => 'required|max:3',
            'billing_interval' => 'required|in:monthly,yearly',
            'document_limit' => 'required|integer|min:0',
            'ai_limit' => 'required|integer|min:0',
            'features' => 'nullable|max:2000',
            'sort_order' => 'nullable|integer|min:0',
        ], [], '/admin/plans');

        $features = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/', (string) ($data['features'] ?? '')) ?: []
        ), static fn (string $line): bool => $line !== ''));

        $this->plans->updateById((int) $plan['id'], [
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'price' => (float) $data['price'],
            'currency' => strtoupper(substr((string) $data['currency'], 0, 3)),
            'billing_interval' => (string) $data['billing_interval'],
            'document_limit' => (int) $data['document_limit'],
            'ai_limit' => (int) $data['ai_limit'],
            'all_templates' => $request->boolean('all_templates') ? 1 : 0,
            'pdf_enabled' => $request->boolean('pdf_enabled') ? 1 : 0,
            'email_enabled' => $request->boolean('email_enabled') ? 1 : 0,
            'is_active' => $request->boolean('is_active') ? 1 : 0,
            'features' => json_encode($features, JSON_UNESCAPED_UNICODE),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        ActivityLog::record(Auth::id(), 'admin.plan_updated', (string) $data['name'], 'plan', (int) $plan['id']);

        $this->success('Plan "' . (string) $data['name'] . '" updated.');
        $this->redirect('/admin/plans');
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request, [
            'slug' => 'required|max:40|alpha_num|unique:plans,slug',
            'name' => 'required|max:60',
            'price' => 'required|numeric|min:0',
            'document_limit' => 'required|integer|min:0',
            'ai_limit' => 'required|integer|min:0',
        ], ['slug.unique' => 'A plan with that slug already exists.'], '/admin/plans');

        $planId = $this->plans->create([
            'slug' => strtolower((string) $data['slug']),
            'name' => (string) $data['name'],
            'description' => (string) $request->input('description', ''),
            'price' => (float) $data['price'],
            'currency' => strtoupper(substr((string) $request->input('currency', 'INR'), 0, 3)),
            'billing_interval' => $request->input('billing_interval') === 'yearly' ? 'yearly' : 'monthly',
            'document_limit' => (int) $data['document_limit'],
            'ai_limit' => (int) $data['ai_limit'],
            'all_templates' => $request->boolean('all_templates') ? 1 : 0,
            'pdf_enabled' => $request->boolean('pdf_enabled') ? 1 : 0,
            'email_enabled' => $request->boolean('email_enabled') ? 1 : 0,
            'features' => json_encode([], JSON_UNESCAPED_UNICODE),
            'is_active' => 1,
            'sort_order' => $request->integer('sort_order', 10),
        ]);

        ActivityLog::record(Auth::id(), 'admin.plan_created', (string) $data['name'], 'plan', $planId);

        $this->success('Plan created.');
        $this->redirect('/admin/plans');
    }

    public function toggle(Request $request): void
    {
        $plan = $this->plans->findPlan($request->paramInt('id'));

        if ($plan === null) {
            $this->error('Plan not found.');
            $this->redirect('/admin/plans');

            return;
        }

        if ((string) $plan['slug'] === 'free' && (int) $plan['is_active'] === 1) {
            $this->error('The Free plan cannot be deactivated — it is the fallback for every account.');
            $this->redirect('/admin/plans');

            return;
        }

        $this->plans->updateById((int) $plan['id'], ['is_active' => (int) $plan['is_active'] === 1 ? 0 : 1]);

        $this->success('Plan updated.');
        $this->redirect('/admin/plans');
    }
}
