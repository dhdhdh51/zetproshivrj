<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Model;

final class Document extends Model
{
    protected string $table = 'documents';

    protected array $fillable = [
        'user_id', 'client_id', 'document_type', 'document_number', 'title', 'summary', 'status',
        'template', 'currency', 'issue_date', 'valid_until', 'client_name', 'client_company',
        'client_email', 'client_phone', 'client_address', 'subtotal', 'tax_total', 'discount_type',
        'discount_value', 'discount_total', 'total', 'notes', 'terms', 'ai_generated', 'ai_prompt',
        'pdf_path', 'pdf_generated_at', 'sent_at',
    ];

    public const STATUSES = ['draft', 'final', 'sent'];

    public function findForUser(int $id, int $userId): array
    {
        $document = $this->find($id);

        if ($document === null) {
            throw new HttpException(404, 'Document not found.');
        }

        if ((int) $document['user_id'] !== $userId) {
            throw new HttpException(403, 'You do not have access to this document.');
        }

        return $document;
    }

    public function items(int $documentId): array
    {
        return Database::select(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY position ASC, id ASC',
            ['id' => $documentId]
        );
    }

    public function withItems(int $id, int $userId): array
    {
        $document = $this->findForUser($id, $userId);
        $document['items'] = $this->items($id);

        return $document;
    }

    public function shareLink(int $documentId): ?array
    {
        return Database::selectOne('SELECT * FROM share_links WHERE document_id = :id LIMIT 1', ['id' => $documentId]);
    }

    public function paginateForUser(int $userId, array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $where = ['d.user_id = :user_id'];
        $params = ['user_id' => $userId];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(d.title LIKE :search OR d.document_number LIKE :search OR d.client_name LIKE :search OR d.client_company LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $type = (string) ($filters['type'] ?? '');
        if (array_key_exists($type, document_types())) {
            $where[] = 'd.document_type = :type';
            $params['type'] = $type;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }

        $clientId = (int) ($filters['client_id'] ?? 0);
        if ($clientId > 0) {
            $where[] = 'd.client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT d.*, sl.token AS share_token, sl.is_active AS share_active
                  FROM documents d
             LEFT JOIN share_links sl ON sl.document_id = d.id
                 WHERE {$whereSql}
                 ORDER BY d.created_at DESC, d.id DESC";

        $countSql = "SELECT COUNT(*) FROM documents d WHERE {$whereSql}";

        return $this->paginateQuery($sql, $params, $page, $perPage, $countSql);
    }

    public function recentForUser(int $userId, int $limit = 5): array
    {
        return Database::select(
            'SELECT id, document_number, document_type, title, client_name, status, total, currency, created_at
               FROM documents WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(25, $limit)),
            ['user_id' => $userId]
        );
    }

    public function statsForUser(int $userId): array
    {
        $row = Database::selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = :period THEN 1 ELSE 0 END) AS this_month,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                COALESCE(SUM(total), 0) AS value
             FROM documents WHERE user_id = :user_id",
            ['user_id' => $userId, 'period' => date('Y-m')]
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'this_month' => (int) ($row['this_month'] ?? 0),
            'drafts' => (int) ($row['drafts'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'value' => (float) ($row['value'] ?? 0),
        ];
    }

    /**
     * Next sequential document number for a user + type, e.g. QT-2026-0001.
     */
    public function nextNumber(int $userId, string $type): string
    {
        $prefix = document_types()[$type]['prefix'] ?? 'DOC';
        $year = date('Y');
        $pattern = $prefix . '-' . $year . '-';

        $max = (int) Database::scalar(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(document_number, :len) AS UNSIGNED)), 0)
               FROM documents
              WHERE user_id = :user_id AND document_number LIKE :pattern",
            [
                'len' => strlen($pattern) + 1,
                'user_id' => $userId,
                'pattern' => $pattern . '%',
            ]
        );

        return $pattern . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function countForPeriod(int $userId, string $period): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM documents WHERE user_id = :user_id AND DATE_FORMAT(created_at, '%Y-%m') = :period",
            ['user_id' => $userId, 'period' => $period]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Admin                                                               */
    /* ------------------------------------------------------------------ */

    public function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1 = 1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(d.title LIKE :search OR d.document_number LIKE :search OR u.email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $type = (string) ($filters['type'] ?? '');
        if (array_key_exists($type, document_types())) {
            $where[] = 'd.document_type = :type';
            $params['type'] = $type;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT d.*, u.name AS user_name, u.email AS user_email
                  FROM documents d
                  JOIN users u ON u.id = d.user_id
                 WHERE {$whereSql}
                 ORDER BY d.created_at DESC";

        $countSql = "SELECT COUNT(*) FROM documents d JOIN users u ON u.id = d.user_id WHERE {$whereSql}";

        return $this->paginateQuery($sql, $params, $page, $perPage, $countSql);
    }

    public function statistics(): array
    {
        return [
            'total' => (int) Database::scalar('SELECT COUNT(*) FROM documents'),
            'this_month' => (int) Database::scalar(
                "SELECT COUNT(*) FROM documents WHERE DATE_FORMAT(created_at, '%Y-%m') = :period",
                ['period' => date('Y-m')]
            ),
            'sent' => (int) Database::scalar("SELECT COUNT(*) FROM documents WHERE status = 'sent'"),
            'by_type' => Database::select(
                'SELECT document_type, COUNT(*) AS total FROM documents GROUP BY document_type ORDER BY total DESC'
            ),
        ];
    }
}
