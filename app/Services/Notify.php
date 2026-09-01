<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * In-app notifications. A row targets exactly one audience: a single user, a
 * whole role, or everyone attached to a branch.
 */
final class Notify
{
    public static function user(int $userId, string $title, string $body = '', array $options = []): int
    {
        return self::create(['user_id' => $userId], $title, $body, $options);
    }

    public static function role(string $roleSlug, string $title, string $body = '', array $options = []): int
    {
        return self::create(['role_slug' => $roleSlug], $title, $body, $options);
    }

    public static function branch(int $branchId, string $title, string $body = '', array $options = []): int
    {
        return self::create(['branch_id' => $branchId], $title, $body, $options);
    }

    public static function admins(string $title, string $body = '', array $options = []): int
    {
        return self::role(Auth::ROLE_ADMIN, $title, $body, $options);
    }

    private static function create(array $target, string $title, string $body, array $options): int
    {
        return Database::insert('notifications', array_merge([
            'user_id' => null,
            'role_slug' => null,
            'branch_id' => null,
        ], $target, [
            'title' => mb_substr($title, 0, 190),
            'body' => mb_substr($body, 0, 500),
            'type' => $options['type'] ?? 'info',
            'link' => $options['link'] ?? null,
            'related_type' => $options['related_type'] ?? null,
            'related_id' => $options['related_id'] ?? null,
            'is_read' => 0,
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Everything addressed to a user: personal rows, their role and their branch.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(array $user, int $limit = 30, bool $unreadOnly = false): array
    {
        $sql = 'SELECT * FROM notifications
                 WHERE (user_id = :uid
                        OR (user_id IS NULL AND role_slug = :role)
                        OR (user_id IS NULL AND role_slug IS NULL AND branch_id = :branch))';

        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, min(200, $limit));

        return Database::select($sql, [
            'uid' => (int) $user['id'],
            'role' => (string) $user['role'],
            'branch' => $user['branch_id'] === null ? 0 : (int) $user['branch_id'],
        ]);
    }

    public static function unreadCount(array $user): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications
              WHERE is_read = 0
                AND (user_id = :uid
                     OR (user_id IS NULL AND role_slug = :role)
                     OR (user_id IS NULL AND role_slug IS NULL AND branch_id = :branch))',
            [
                'uid' => (int) $user['id'],
                'role' => (string) $user['role'],
                'branch' => $user['branch_id'] === null ? 0 : (int) $user['branch_id'],
            ]
        );
    }

    public static function markRead(int $id, array $user): void
    {
        Database::update(
            'notifications',
            ['is_read' => 1, 'read_at' => now(), 'updated_at' => now()],
            'id = :id AND (user_id = :uid OR user_id IS NULL)',
            ['id' => $id, 'uid' => (int) $user['id']]
        );
    }

    public static function markAllRead(array $user): int
    {
        return Database::update(
            'notifications',
            ['is_read' => 1, 'read_at' => now(), 'updated_at' => now()],
            'is_read = 0
             AND (user_id = :uid
                  OR (user_id IS NULL AND role_slug = :role)
                  OR (user_id IS NULL AND role_slug IS NULL AND branch_id = :branch))',
            [
                'uid' => (int) $user['id'],
                'role' => (string) $user['role'],
                'branch' => $user['branch_id'] === null ? 0 : (int) $user['branch_id'],
            ]
        );
    }
}
