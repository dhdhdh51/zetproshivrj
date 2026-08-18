<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Model;

final class Client extends Model
{
    protected string $table = 'clients';

    protected array $fillable = ['user_id', 'name', 'company', 'email', 'phone', 'address', 'notes'];

    public function forUser(int $userId, string $orderBy = 'name ASC'): array
    {
        return $this->where(['user_id' => $userId], $orderBy);
    }

    /**
     * Fetch a client and make sure it belongs to the given user.
     */
    public function findForUser(int $id, int $userId): array
    {
        $client = $this->find($id);

        if ($client === null) {
            throw new HttpException(404, 'Client not found.');
        }

        if ((int) $client['user_id'] !== $userId) {
            throw new HttpException(403, 'You do not have access to this client.');
        }

        return $client;
    }

    public function paginateForUser(int $userId, string $search = '', int $page = 1, int $perPage = 12): array
    {
        $params = ['user_id' => $userId];
        $where = 'c.user_id = :user_id';

        if ($search !== '') {
            $where .= ' AND (c.name LIKE :search OR c.company LIKE :search OR c.email LIKE :search OR c.phone LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql = "SELECT c.*, (SELECT COUNT(*) FROM documents d WHERE d.client_id = c.id) AS documents_count
                  FROM clients c
                 WHERE {$where}
                 ORDER BY c.name ASC";

        $countSql = "SELECT COUNT(*) FROM clients c WHERE {$where}";

        return $this->paginateQuery($sql, $params, $page, $perPage, $countSql);
    }

    public function search(int $userId, string $term, int $limit = 10): array
    {
        return Database::select(
            'SELECT id, name, company, email, phone, address FROM clients
              WHERE user_id = :user_id AND (name LIKE :term OR company LIKE :term OR email LIKE :term)
              ORDER BY name ASC LIMIT ' . max(1, min(50, $limit)),
            ['user_id' => $userId, 'term' => '%' . $term . '%']
        );
    }

    public function documentsFor(int $clientId, int $limit = 10): array
    {
        return Database::select(
            'SELECT id, document_number, document_type, title, status, total, currency, created_at
               FROM documents WHERE client_id = :id ORDER BY created_at DESC LIMIT ' . max(1, min(50, $limit)),
            ['id' => $clientId]
        );
    }
}
