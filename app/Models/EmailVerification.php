<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class EmailVerification extends Model
{
    protected string $table = 'email_verifications';
    protected bool $timestamps = false;

    protected array $fillable = ['user_id', 'token', 'expires_at', 'verified_at', 'created_at'];

    public function issue(int $userId, int $hours = 48): string
    {
        Database::delete($this->table, 'user_id = :user_id AND verified_at IS NULL', ['user_id' => $userId]);

        $plain = bin2hex(random_bytes(32));

        $this->create([
            'user_id' => $userId,
            'token' => hash('sha256', $plain),
            'expires_at' => date('Y-m-d H:i:s', time() + ($hours * 3600)),
            'created_at' => now(),
        ]);

        return $plain;
    }

    public function findValid(string $plainToken): ?array
    {
        return Database::selectOne(
            'SELECT * FROM email_verifications
              WHERE token = :token AND verified_at IS NULL AND expires_at > NOW() LIMIT 1',
            ['token' => hash('sha256', $plainToken)]
        );
    }

    public function consume(int $id): void
    {
        $this->updateById($id, ['verified_at' => now()]);
    }
}
