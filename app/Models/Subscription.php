<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Subscription extends Model
{
    protected string $table = 'subscriptions';

    protected array $fillable = ['user_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'cancelled_at'];

    public function activeForUser(int $userId): ?array
    {
        return Database::selectOne(
            "SELECT s.*, p.slug AS plan_slug, p.name AS plan_name, p.price, p.currency, p.document_limit,
                    p.ai_limit, p.all_templates, p.pdf_enabled, p.email_enabled, p.features
               FROM subscriptions s
               JOIN plans p ON p.id = s.plan_id
              WHERE s.user_id = :user_id
                AND s.status = 'active'
                AND (s.ends_at IS NULL OR s.ends_at > NOW())
              ORDER BY s.id DESC
              LIMIT 1",
            ['user_id' => $userId]
        );
    }

    public function historyForUser(int $userId, int $limit = 10): array
    {
        return Database::select(
            'SELECT s.*, p.name AS plan_name, p.price, p.currency
               FROM subscriptions s JOIN plans p ON p.id = s.plan_id
              WHERE s.user_id = :user_id ORDER BY s.id DESC LIMIT ' . max(1, min(50, $limit)),
            ['user_id' => $userId]
        );
    }

    /**
     * Activate a plan for a user: expire previous subscriptions and create a new active one.
     */
    public function activate(int $userId, int $planId, int $months = 1): int
    {
        Database::statement(
            "UPDATE subscriptions SET status = 'expired', updated_at = :now
              WHERE user_id = :user_id AND status IN ('active','pending')",
            ['now' => now(), 'user_id' => $userId]
        );

        return $this->create([
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => date('Y-m-d H:i:s', strtotime('+' . max(1, $months) . ' month')),
        ]);
    }

    public function cancel(int $id): void
    {
        $this->updateById($id, ['status' => 'cancelled', 'cancelled_at' => now()]);
    }

    public function activeCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND (ends_at IS NULL OR ends_at > NOW())"
        );
    }

    /** Mark subscriptions whose end date has passed as expired. */
    public function expireOverdue(): int
    {
        return Database::statement(
            "UPDATE subscriptions SET status = 'expired', updated_at = :now
              WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at <= NOW()",
            ['now' => now()]
        )->rowCount();
    }
}
