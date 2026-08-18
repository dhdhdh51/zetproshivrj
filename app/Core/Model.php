<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Very small active-record-ish base class. Models return plain arrays which keeps
 * views and services simple, while all SQL stays parameterised.
 */
abstract class Model
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected bool $timestamps = true;

    /** Columns that may be mass assigned. */
    protected array $fillable = [];

    public function table(): string
    {
        return $this->table;
    }

    public function find(int $id): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :id LIMIT 1', $this->table, $this->primaryKey),
            ['id' => $id]
        );
    }

    public function findOrFail(int $id): array
    {
        $row = $this->find($id);

        if ($row === null) {
            throw new HttpException(404, 'Record not found.');
        }

        return $row;
    }

    public function findBy(string $column, mixed $value): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :value LIMIT 1', $this->table, $column),
            ['value' => $value]
        );
    }

    public function where(array $conditions, string $orderBy = '', int $limit = 0): array
    {
        $clauses = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            $clauses[] = sprintf('`%s` = :%s', $column, $column);
            $params[$column] = $value;
        }

        $sql = sprintf('SELECT * FROM `%s`', $this->table);

        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . $orderBy;
        }

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        return Database::select($sql, $params);
    }

    public function all(string $orderBy = ''): array
    {
        return $this->where([], $orderBy);
    }

    public function count(array $conditions = []): int
    {
        $clauses = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            $clauses[] = sprintf('`%s` = :%s', $column, $column);
            $params[$column] = $value;
        }

        $sql = sprintf('SELECT COUNT(*) FROM `%s`', $this->table);
        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        return (int) Database::scalar($sql, $params);
    }

    public function create(array $data): int
    {
        $data = $this->filter($data);

        if ($this->timestamps) {
            $data['created_at'] ??= now();
            $data['updated_at'] ??= now();
        }

        return Database::insert($this->table, $data);
    }

    public function updateById(int $id, array $data): int
    {
        $data = $this->filter($data);

        if ($data === []) {
            return 0;
        }

        if ($this->timestamps) {
            $data['updated_at'] = now();
        }

        return Database::update(
            $this->table,
            $data,
            sprintf('`%s` = :pk', $this->primaryKey),
            ['pk' => $id]
        );
    }

    public function deleteById(int $id): int
    {
        return Database::delete($this->table, sprintf('`%s` = :pk', $this->primaryKey), ['pk' => $id]);
    }

    /**
     * Paginate an arbitrary SQL statement (without LIMIT).
     *
     * @return array{data:array, total:int, page:int, per_page:int, last_page:int, from:int, to:int}
     */
    protected function paginateQuery(string $sql, array $params, int $page, int $perPage, string $countSql = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        if ($countSql === '') {
            $countSql = 'SELECT COUNT(*) FROM (' . $sql . ') AS aggregate_query';
        }

        $total = (int) Database::scalar($countSql, $params);
        $rows = Database::select($sql . sprintf(' LIMIT %d OFFSET %d', $perPage, $offset), $params);
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => $offset + count($rows),
        ];
    }

    protected function filter(array $data): array
    {
        if ($this->fillable === []) {
            return $data;
        }

        $allowed = array_merge($this->fillable, ['created_at', 'updated_at']);

        return array_intersect_key($data, array_flip($allowed));
    }
}
