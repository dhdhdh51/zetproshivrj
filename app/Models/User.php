<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class User extends Model
{
    protected string $table = 'users';

    protected array $fillable = [
        'name', 'email', 'password', 'google_id', 'avatar', 'role', 'status',
        'email_verified_at', 'remember_token', 'last_login_at',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', strtolower(trim($email)));
    }

    public function findByGoogleId(string $googleId): ?array
    {
        return $this->findBy('google_id', $googleId);
    }

    public function markVerified(int $id): void
    {
        $this->updateById($id, ['email_verified_at' => now()]);
    }

    public function updatePassword(int $id, string $plainPassword): void
    {
        Database::update(
            $this->table,
            ['password' => password_hash($plainPassword, PASSWORD_DEFAULT), 'remember_token' => null, 'updated_at' => now()],
            'id = :id',
            ['id' => $id]
        );
    }

    /**
     * Admin listing with search, status filter and pagination.
     */
    public function paginateForAdmin(string $search = '', string $status = '', string $role = '', int $page = 1, int $perPage = 20): array
    {
        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(u.name LIKE :search OR u.email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }

        if (in_array($role, ['user', 'admin'], true)) {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT u.*,
                       (SELECT COUNT(*) FROM documents d WHERE d.user_id = u.id) AS documents_count,
                       (SELECT COUNT(*) FROM ai_generations a WHERE a.user_id = u.id) AS ai_count,
                       (SELECT p.name FROM subscriptions s
                          JOIN plans p ON p.id = s.plan_id
                         WHERE s.user_id = u.id AND s.status = 'active'
                           AND (s.ends_at IS NULL OR s.ends_at > NOW())
                         ORDER BY s.id DESC LIMIT 1) AS plan_name
                  FROM users u
                 WHERE {$whereSql}
                 ORDER BY u.created_at DESC";

        $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereSql}";

        return $this->paginateQuery($sql, $params, $page, $perPage, $countSql);
    }

    public function statistics(): array
    {
        return [
            'total' => (int) Database::scalar('SELECT COUNT(*) FROM users'),
            'active' => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'admins' => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'"),
            'this_month' => (int) Database::scalar(
                "SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = :period",
                ['period' => date('Y-m')]
            ),
        ];
    }
}
