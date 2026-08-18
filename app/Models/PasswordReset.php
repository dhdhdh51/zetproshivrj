<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class PasswordReset extends Model
{
    protected string $table = 'password_resets';
    protected bool $timestamps = false;

    protected array $fillable = ['email', 'token', 'expires_at', 'used_at', 'created_at'];

    /**
     * Issue a reset token. Returns the plain token to email (only the hash is stored).
     */
    public function issue(string $email, int $minutes = 60): string
    {
        Database::delete($this->table, 'email = :email AND used_at IS NULL', ['email' => $email]);

        $plain = bin2hex(random_bytes(32));

        $this->create([
            'email' => $email,
            'token' => hash('sha256', $plain),
            'expires_at' => date('Y-m-d H:i:s', time() + ($minutes * 60)),
            'created_at' => now(),
        ]);

        return $plain;
    }

    public function findValid(string $plainToken): ?array
    {
        return Database::selectOne(
            'SELECT * FROM password_resets
              WHERE token = :token AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            ['token' => hash('sha256', $plainToken)]
        );
    }

    public function consume(int $id): void
    {
        $this->updateById($id, ['used_at' => now()]);
    }

    public function purgeExpired(): void
    {
        Database::delete($this->table, 'expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}
