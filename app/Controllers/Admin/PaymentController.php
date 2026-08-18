<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PayUService;

final class PaymentController extends Controller
{
    private Payment $payments;

    public function __construct()
    {
        $this->payments = new Payment();
    }

    public function index(Request $request): void
    {
        $filters = [
            'search' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $payu = new PayUService();

        $this->view('admin.payments.index', [
            'title' => 'Payments',
            'filters' => $filters,
            'payments' => $this->payments->paginateForAdmin($filters, $request->integer('page', 1), 20),
            'stats' => $this->payments->statistics(),
            'active_subscriptions' => (new Subscription())->activeCount(),
            'payu_configured' => $payu->isConfigured(),
            'payu_mode' => $payu->mode(),
        ], 'layouts.admin');
    }

    public function show(Request $request): void
    {
        $payment = $this->payments->findOrFail($request->paramInt('id'));
        $raw = json_decode((string) ($payment['raw_response'] ?? ''), true);

        $this->view('admin.payments.show', [
            'title' => 'Payment ' . (string) $payment['txnid'],
            'payment' => $payment,
            'user' => (new User())->find((int) $payment['user_id']),
            'raw' => is_array($raw) ? $raw : [],
        ], 'layouts.admin');
    }

    /**
     * Re-check a pending/failed payment against PayU's verify_payment API.
     */
    public function verify(Request $request): void
    {
        $payment = $this->payments->findOrFail($request->paramInt('id'));
        $payu = new PayUService();

        if (!$payu->isConfigured()) {
            $this->error('PayU is not configured.');
            $this->redirect('/admin/payments/' . (int) $payment['id']);

            return;
        }

        $result = $payu->verifyPayment((string) $payment['txnid']);

        if ($result['success'] && (string) $payment['status'] !== 'success') {
            $subscriptionId = (new Subscription())->activate(
                (int) $payment['user_id'],
                (int) $payment['plan_id'],
                1
            );

            $this->payments->updateById((int) $payment['id'], [
                'status' => 'success',
                'subscription_id' => $subscriptionId,
                'verified_at' => now(),
                'paid_at' => now(),
                'raw_response' => json_encode($result['raw'], JSON_UNESCAPED_SLASHES),
            ]);

            $this->success('PayU confirmed this payment — the subscription has been activated.');
        } elseif ($result['success']) {
            $this->info('PayU confirms this payment was already successful.');
        } else {
            $this->payments->updateById((int) $payment['id'], [
                'verified_at' => now(),
                'raw_response' => json_encode($result['raw'], JSON_UNESCAPED_SLASHES),
            ]);

            $this->error('PayU reports status "' . $result['status'] . '". ' . $result['message']);
        }

        $this->redirect('/admin/payments/' . (int) $payment['id']);
    }
}
