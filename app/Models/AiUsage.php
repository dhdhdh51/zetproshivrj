<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Monthly usage counters (one row per user per YYYY-MM period).
 */
final class AiUsage extends Model
{
    protected string $table = 'ai_usage';

    protected array $fillable = ['user_id', 'period', 'ai_generations', 'documents_created', 'emails_sent'];

    public function forPeriod(int $userId, ?string $period = null): array
    {
        $period ??= date('Y-m');

        $row = Database::selectOne(
            'SELECT * FROM ai_usage WHERE user_id = :user_id AND period = :period LIMIT 1',
            ['user_id' => $userId, 'period' => $period]
        );

        return $row ?? [
            'user_id' => $userId,
            'period' => $period,
            'ai_generations' => 0,
            'documents_created' => 0,
            'emails_sent' => 0,
        ];
    }

    public function increment(int $userId, string $column, int $amount = 1, ?string $period = null): void
    {
        if (!in_array($column, ['ai_generations', 'documents_created', 'emails_sent'], true)) {
            return;
        }

        $period ??= date('Y-m');

        Database::statement(
            sprintf(
                'INSERT INTO ai_usage (user_id, period, `%1$s`, created_at, updated_at)
                 VALUES (:user_id, :period, :amount, :now, :now)
                 ON DUPLICATE KEY UPDATE `%1$s` = `%1$s` + :amount, updated_at = :now',
                $column
            ),
            [
                'user_id' => $userId,
                'period' => $period,
                'amount' => $amount,
                'now' => now(),
            ]
        );
    }

    public function totalsForUser(int $userId): array
    {
        $row = Database::selectOne(
            'SELECT COALESCE(SUM(ai_generations), 0) AS ai, COALESCE(SUM(documents_created), 0) AS docs,
                    COALESCE(SUM(emails_sent), 0) AS emails
               FROM ai_usage WHERE user_id = :user_id',
            ['user_id' => $userId]
        ) ?? [];

        return [
            'ai_generations' => (int) ($row['ai'] ?? 0),
            'documents_created' => (int) ($row['docs'] ?? 0),
            'emails_sent' => (int) ($row['emails'] ?? 0),
        ];
    }

    public function monthlyTrend(int $months = 6): array
    {
        return Database::select(
            'SELECT period, SUM(ai_generations) AS ai_generations, SUM(documents_created) AS documents_created
               FROM ai_usage GROUP BY period ORDER BY period DESC LIMIT ' . max(1, min(24, $months))
        );
    }
}
