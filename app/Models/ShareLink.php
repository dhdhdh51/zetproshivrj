<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class ShareLink extends Model
{
    protected string $table = 'share_links';

    protected array $fillable = ['document_id', 'user_id', 'token', 'is_active', 'views', 'last_viewed_at', 'expires_at'];

    public function forDocument(int $documentId): ?array
    {
        return $this->findBy('document_id', $documentId);
    }

    public function findByToken(string $token): ?array
    {
        return Database::selectOne(
            'SELECT sl.*, d.id AS doc_id FROM share_links sl JOIN documents d ON d.id = sl.document_id
              WHERE sl.token = :token LIMIT 1',
            ['token' => $token]
        );
    }

    /**
     * Create (or re-enable) a share link with a cryptographically secure token.
     */
    public function enable(int $documentId, int $userId): array
    {
        $existing = $this->forDocument($documentId);

        if ($existing !== null) {
            $this->updateById((int) $existing['id'], ['is_active' => 1]);

            return $this->find((int) $existing['id']) ?? $existing;
        }

        $id = $this->create([
            'document_id' => $documentId,
            'user_id' => $userId,
            'token' => bin2hex(random_bytes(24)),
            'is_active' => 1,
        ]);

        return $this->find($id) ?? [];
    }

    public function disable(int $documentId): void
    {
        $existing = $this->forDocument($documentId);

        if ($existing !== null) {
            $this->updateById((int) $existing['id'], ['is_active' => 0]);
        }
    }

    public function registerView(int $id): void
    {
        Database::statement(
            'UPDATE share_links SET views = views + 1, last_viewed_at = :now WHERE id = :id',
            ['now' => now(), 'id' => $id]
        );
    }
}
