<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Payment extends Model
{
    protected string $table = 'payments';

    protected array $fillable = [
        'user_id', 'plan_id', 'subscription_id', 'gateway', 'txnid', 'gateway_payment_id', 'amount',
        'currency', 'status', 'payment_mode', 'bank_ref_num', 'error_message', 'raw_response',
        'verified_at', 'paid_at',
    ];

    public function findByTxnId(string $txnid): ?array
    {
        return $this->findBy('txnid', $txnid);
    }

    public function forUser(int $userId, int $limit = 20): array
    {
        return Database::select(
            'SELECT pay.*, p.name AS plan_name
               FROM payments pay LEFT JOIN plans p ON p.id = pay.plan_id
              WHERE pay.user_id = :user_id ORDER BY pay.id DESC LIMIT ' . max(1, min(100, $limit)),
            ['user_id' => $userId]
        );
    }

    public function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1 = 1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(pay.txnid LIKE :search OR pay.gateway_payment_id LIKE :search OR u.email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['pending', 'success', 'failed', 'cancelled'], true)) {
            $where[] = 'pay.status = :status';
            $params['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT pay.*, u.name AS user_name, u.email AS user_email, p.name AS plan_name
                  FROM payments pay
                  JOIN users u ON u.id = pay.user_id
             LEFT JOIN plans p ON p.id = pay.plan_id
                 WHERE {$whereSql}
                 ORDER BY pay.id DESC";

        $countSql = "SELECT COUNT(*) FROM payments pay JOIN users u ON u.id = pay.user_id WHERE {$whereSql}";

        return $this->paginateQuery($sql, $params, $page, $perPage, $countSql);
    }

    public function statistics(): array
    {
        $row = Database::selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN status = 'success' AND DATE_FORMAT(paid_at, '%Y-%m') = :period THEN amount ELSE 0 END), 0) AS revenue_this_month
             FROM payments"
            , ['period' => date('Y-m')]
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'successful' => (int) ($row['successful'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0),
            'revenue_this_month' => (float) ($row['revenue_this_month'] ?? 0),
        ];
    }
}
