<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\AiGeneration;
use App\Models\AiUsage;
use App\Models\Document;
use App\Models\EmailLog;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MailService;
use App\Services\OpenRouterService;
use App\Services\PayUService;
use App\Services\PDFService;

final class AdminDashboardController extends Controller
{
    public function index(Request $request): void
    {
        $users = new User();
        $documents = new Document();
        $payments = new Payment();

        $ai = new OpenRouterService();
        $mail = new MailService();
        $payu = new PayUService();
        $pdf = new PDFService();

        $this->view('admin.dashboard', [
            'title' => 'Admin dashboard',
            'users' => $users->statistics(),
            'documents' => $documents->statistics(),
            'ai' => (new AiGeneration())->statistics(),
            'payments' => $payments->statistics(),
            'emails' => (new EmailLog())->statistics(),
            'active_subscriptions' => (new Subscription())->activeCount(),
            'recent_users' => Database::select(
                'SELECT id, name, email, status, created_at FROM users ORDER BY id DESC LIMIT 6'
            ),
            'recent_documents' => Database::select(
                'SELECT d.id, d.document_number, d.document_type, d.title, d.total, d.currency, d.created_at,
                        u.email AS user_email
                   FROM documents d JOIN users u ON u.id = d.user_id
                  ORDER BY d.id DESC LIMIT 6'
            ),
            'recent_payments' => Database::select(
                'SELECT p.id, p.txnid, p.amount, p.currency, p.status, p.created_at, u.email AS user_email
                   FROM payments p JOIN users u ON u.id = p.user_id
                  ORDER BY p.id DESC LIMIT 6'
            ),
            'activity' => (new ActivityLog())->recent(10),
            'usage_trend' => (new AiUsage())->monthlyTrend(6),
            'system' => [
                'php' => PHP_VERSION,
                'database' => Database::isConnected(),
                'ai_configured' => $ai->isConfigured(),
                'ai_enabled' => $ai->isEnabled(),
                'ai_model' => $ai->model(),
                'mail_configured' => $mail->isConfigured(),
                'mail_available' => $mail->isAvailable(),
                'payu_configured' => $payu->isConfigured(),
                'payu_mode' => $payu->mode(),
                'pdf_available' => $pdf->isAvailable(),
                'storage_writable' => is_writable(storage_path('generated')) && is_writable(storage_path('uploads')),
            ],
        ], 'layouts.admin');
    }
}
