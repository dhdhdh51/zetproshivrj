<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class AiGeneration extends Model
{
    protected string $table = 'ai_generations';
    protected bool $timestamps = false;

    protected array $fillable = [
        'user_id', 'document_id', 'type', 'model', 'prompt', 'response', 'prompt_tokens',
        'completion_tokens', 'total_tokens', 'duration_ms', 'status', 'error_message', 'created_at',
    ];

    public function log(array $data): int
    {
        $data['created_at'] ??= now();
        $data['prompt'] = mb_substr((string) ($data['prompt'] ?? ''), 0, 4000);
        $data['response'] = mb_substr((string) ($data['response'] ?? ''), 0, 60000);

        return $this->create($data);
    }

    public function countForPeriod(int $userId, string $period): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM ai_generations
              WHERE user_id = :user_id AND status = 'success' AND DATE_FORMAT(created_at, '%Y-%m') = :period",
            ['user_id' => $userId, 'period' => $period]
        );
    }

    public function recentForUser(int $userId, int $limit = 10): array
    {
        return Database::select(
            'SELECT id, type, model, status, total_tokens, created_at FROM ai_generations
              WHERE user_id = :user_id ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
            ['user_id' => $userId]
        );
    }

    public function statistics(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    COALESCE(SUM(total_tokens), 0) AS tokens,
                    SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = :period THEN 1 ELSE 0 END) AS this_month
               FROM ai_generations",
            ['period' => date('Y-m')]
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'tokens' => (int) ($row['tokens'] ?? 0),
            'this_month' => (int) ($row['this_month'] ?? 0),
        ];
    }
}
