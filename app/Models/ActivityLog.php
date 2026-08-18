<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Model;

final class ActivityLog extends Model
{
    protected string $table = 'activity_logs';
    protected bool $timestamps = false;

    protected array $fillable = [
        'user_id', 'action', 'description', 'entity_type', 'entity_id', 'ip_address', 'user_agent', 'created_at',
    ];

    public static function record(
        ?int $userId,
        string $action,
        string $description = '',
        ?string $entityType = null,
        ?int $entityId = null
    ): void {
        try {
            (new self())->create([
                'user_id' => $userId,
                'action' => mb_substr($action, 0, 80),
                'description' => mb_substr($description, 0, 255),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let activity logging break a user flow.
            Logger::warning('Activity log failed: ' . $e->getMessage());
        }
    }

    public function recent(int $limit = 20): array
    {
        return Database::select(
            'SELECT al.*, u.name AS user_name, u.email AS user_email
               FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id
              ORDER BY al.id DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    public function forUser(int $userId, int $limit = 20): array
    {
        return Database::select(
            'SELECT * FROM activity_logs WHERE user_id = :user_id ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            ['user_id' => $userId]
        );
    }
}
