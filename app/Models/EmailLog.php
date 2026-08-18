<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class EmailLog extends Model
{
    protected string $table = 'email_logs';
    protected bool $timestamps = false;

    protected array $fillable = [
        'user_id', 'document_id', 'type', 'to_email', 'subject', 'body', 'attachment',
        'status', 'error_message', 'sent_at', 'created_at',
    ];

    public function log(array $data): int
    {
        $data['created_at'] ??= now();
        $data['body'] = mb_substr((string) ($data['body'] ?? ''), 0, 60000);

        return $this->create($data);
    }

    public function forDocument(int $documentId): array
    {
        return Database::select(
            'SELECT * FROM email_logs WHERE document_id = :id ORDER BY id DESC LIMIT 20',
            ['id' => $documentId]
        );
    }

    public function recent(int $limit = 20): array
    {
        return Database::select(
            'SELECT el.*, u.email AS user_email FROM email_logs el
             LEFT JOIN users u ON u.id = el.user_id
             ORDER BY el.id DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    public function statistics(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
               FROM email_logs"
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }
}
