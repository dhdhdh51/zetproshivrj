<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PayUService;
use App\Services\UsageService;

final class BillingController extends Controller
{
    private Plan $plans;
    private Payment $payments;
    private Subscription $subscriptions;
    private PayUService $payu;
    private UsageService $usage;

    public function __construct()
    {
        $this->plans = new Plan();
        $this->payments = new Payment();
        $this->subscriptions = new Subscription();
        $this->payu = new PayUService();
        $this->usage = new UsageService();
    }

    /* ================================================================== */
    /* Pricing                                                             */
    /* ================================================================== */

    public function pricing(Request $request): void
    {
        $userId = Auth::id();

        $this->view('billing.pricing', [
            'title' => 'Pricing',
            'meta_description' => 'Simple monthly pricing for ' . app_name() . '. Start free, upgrade when you need more documents and AI generations.',
            'plans' => $this->plans->activePlans(),
            'current' => $userId === null ? null : $this->usage->currentPlan($userId),
            'summary' => $userId === null ? null : $this->usage->summary($userId),
            'payu_ready' => $this->payu->isConfigured(),
            'payu_mode' => $this->payu->mode(),
        ], Auth::check() ? 'layouts.app' : 'layouts.public');
    }

    public function index(Request $request): void
    {
        $userId = (int) Auth::id();

        $this->view('billing.index', [
            'title' => 'Billing & usage',
            'summary' => $this->usage->summary($userId),
            'plans' => $this->plans->activePlans(),
            'payments' => $this->payments->forUser($userId, 20),
            'subscriptions' => $this->subscriptions->historyForUser($userId, 10),
            'payu_ready' => $this->payu->isConfigured(),
        ]);
    }

    /* ================================================================== */
    /* Checkout                                                            */
    /* ================================================================== */

    public function checkout(Request $request): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->redirect('/login');

            return;
        }

        $userId = (int) $user['id'];
        $slug = (string) $request->input('plan', '');
        $plan = $this->plans->findBySlug($slug);

        if ($plan === null || (int) $plan['is_active'] !== 1) {
            $this->error('That plan is not available.');
            $this->redirect('/pricing');

            return;
        }

        if ((float) $plan['price'] <= 0) {
            $this->info('The Free plan is already available to every account — no payment needed.');
            $this->redirect('/billing');

            return;
        }

        if (!$this->payu->isConfigured()) {
            $this->error('Online payments are not configured yet. Please contact support to upgrade.');
            $this->redirect('/pricing');

            return;
        }

        $txnid = $this->payu->newTransactionId();

        $paymentId = $this->payments->create([
            'user_id' => $userId,
            'plan_id' => (int) $plan['id'],
            'gateway' => 'payu',
            'txnid' => $txnid,
            'amount' => (float) $plan['price'],
            'currency' => (string) $plan['currency'],
            'status' => 'pending',
        ]);

        $checkout = $this->payu->buildRequest([
            'txnid' => $txnid,
            'amount' => (float) $plan['price'],
            'productinfo' => app_name() . ' ' . (string) $plan['name'] . ' plan',
            'firstname' => (string) $user['name'],
            'email' => (string) $user['email'],
            'phone' => '9999999999',
            'surl' => url('billing/payu/success'),
            'furl' => url('billing/payu/failure'),
            'udf1' => (string) $userId,
            'udf2' => (string) $plan['id'],
        ]);

        ActivityLog::record($userId, 'payment.started', $txnid . ' · ' . (string) $plan['name'], 'payment', $paymentId);

        // Auto-submitting form -> PayU hosted checkout.
        $this->view('billing.checkout', [
            'title' => 'Redirecting to PayU',
            'action' => $checkout['action'],
            'fields' => $checkout['fields'],
            'plan' => $plan,
            'mode' => $this->payu->mode(),
        ], 'layouts.blank');
    }

    /* ================================================================== */
    /* PayU callbacks                                                      */
    /* ================================================================== */

    public function payuSuccess(Request $request): void
    {
        $this->handleCallback($request, true);
    }

    public function payuFailure(Request $request): void
    {
        $this->handleCallback($request, false);
    }

    private function handleCallback(Request $request, bool $expectSuccess): void
    {
        $post = $_POST !== [] ? $_POST : $_GET;
        $txnid = (string) ($post['txnid'] ?? '');

        if ($txnid === '') {
            $this->error('We did not receive a valid payment reference from PayU.');
            $this->redirect('/pricing');

            return;
        }

        $payment = $this->payments->findByTxnId($txnid);

        if ($payment === null) {
            Logger::warning('PayU callback for unknown txnid', ['txnid' => $txnid]);
            $this->error('We could not match that payment to an order.');
            $this->redirect('/pricing');

            return;
        }

        $userId = (int) $payment['user_id'];

        // Already processed (double callback / refresh).
        if ((string) $payment['status'] === 'success') {
            $this->success('This payment was already confirmed. Your plan is active.');
            $this->redirect(Auth::check() ? '/billing' : '/login');

            return;
        }

        $confirmation = $this->payu->confirm($post);
        $postedAmount = round((float) ($post['amount'] ?? 0), 2);
        $expectedAmount = round((float) $payment['amount'], 2);
        $amountMatches = abs($postedAmount - $expectedAmount) < 0.01;

        $raw = json_encode($this->redactCallback($post), JSON_UNESCAPED_SLASHES);

        if (!$expectSuccess || !$confirmation['paid'] || !$amountMatches) {
            $reason = !$amountMatches && $confirmation['paid']
                ? sprintf('Amount mismatch: expected %.2f, received %.2f.', $expectedAmount, $postedAmount)
                : $confirmation['reason'];

            $this->payments->updateById((int) $payment['id'], [
                'status' => strtolower((string) ($post['status'] ?? '')) === 'pending' ? 'pending' : 'failed',
                'gateway_payment_id' => (string) ($post['mihpayid'] ?? '') ?: null,
                'payment_mode' => (string) ($post['mode'] ?? '') ?: null,
                'bank_ref_num' => (string) ($post['bank_ref_num'] ?? '') ?: null,
                'error_message' => mb_substr($reason, 0, 255),
                'raw_response' => $raw,
                'verified_at' => now(),
            ]);

            ActivityLog::record($userId, 'payment.failed', $txnid . ' · ' . $reason, 'payment', (int) $payment['id']);
            Logger::warning('PayU payment not completed', ['txnid' => $txnid, 'reason' => $reason]);

            $this->error('Payment was not completed: ' . $reason);
            $this->redirect(Auth::check() ? '/pricing' : '/login');

            return;
        }

        // Verified — activate the plan.
        $plan = $this->plans->findPlan((int) $payment['plan_id']);
        $months = ($plan !== null && (string) $plan['billing_interval'] === 'yearly') ? 12 : 1;
        $subscriptionId = $this->subscriptions->activate($userId, (int) $payment['plan_id'], $months);

        $this->payments->updateById((int) $payment['id'], [
            'status' => 'success',
            'subscription_id' => $subscriptionId,
            'gateway_payment_id' => (string) ($post['mihpayid'] ?? '') ?: null,
            'payment_mode' => (string) ($post['mode'] ?? '') ?: null,
            'bank_ref_num' => (string) ($post['bank_ref_num'] ?? '') ?: null,
            'raw_response' => $raw,
            'verified_at' => now(),
            'paid_at' => now(),
        ]);

        ActivityLog::record($userId, 'payment.success', $txnid, 'payment', (int) $payment['id']);

        // PayU posts from its own domain, so the session cookie may be missing (SameSite).
        if (!Auth::check()) {
            Auth::loginById($userId);
        }

        $this->success(sprintf(
            'Payment confirmed — your %s plan is active. Your new limits are live immediately.',
            (string) ($plan['name'] ?? 'new')
        ));

        $this->redirect('/billing');
    }

    /**
     * Never store raw card-ish fields in our database.
     */
    private function redactCallback(array $post): array
    {
        $allowed = [
            'mihpayid', 'mode', 'status', 'unmappedstatus', 'txnid', 'amount', 'addedon', 'productinfo',
            'firstname', 'email', 'phone', 'bank_ref_num', 'bankcode', 'error', 'error_Message',
            'PG_TYPE', 'net_amount_debit', 'discount', 'udf1', 'udf2', 'payuMoneyId',
        ];

        $clean = [];

        foreach ($allowed as $key) {
            if (isset($post[$key])) {
                $clean[$key] = is_scalar($post[$key]) ? (string) $post[$key] : '';
            }
        }

        return $clean;
    }
}
