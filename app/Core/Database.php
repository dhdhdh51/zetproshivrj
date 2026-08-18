<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. Every query in the application goes through here so that
 * prepared statements are always used.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $lastError = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = (string) Config::get('database.driver', 'mysql');
        $host = (string) Config::get('database.host', 'localhost');
        $port = (int) Config::get('database.port', 3306);
        $name = (string) Config::get('database.database', '');
        $user = (string) Config::get('database.username', '');
        $pass = (string) Config::get('database.password', '');
        $charset = (string) Config::get('database.charset', 'utf8mb4');
        $socket = (string) Config::get('database.socket', '');

        if ($socket !== '') {
            $dsn = sprintf('%s:unix_socket=%s;dbname=%s;charset=%s', $driver, $socket, $name, $charset);
        } else {
            $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s', $driver, $host, $port, $name, $charset);
        }

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            self::$lastError = $e->getMessage();
            Logger::error('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Unable to connect to the database. Check your config/config.php settings.', 0, $e);
        }

        return self::$pdo;
    }

    public static function isConnected(): bool
    {
        try {
            self::pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function statement(string $sql, array $params = []): PDOStatement
    {
        [$sql, $params] = self::expandRepeatedPlaceholders($sql, $params);

        $statement = self::pdo()->prepare($sql);

        foreach ($params as $key => $value) {
            $placeholder = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($placeholder, $value, $type);
        }

        $statement->execute();

        return $statement;
    }

    /**
     * Native (non-emulated) prepared statements only allow a named placeholder
     * to appear once. Queries such as `WHERE a LIKE :q OR b LIKE :q` and
     * `INSERT ... ON DUPLICATE KEY UPDATE` are far more readable with repeats,
     * so each extra occurrence is rewritten to its own unique placeholder here.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function expandRepeatedPlaceholders(string $sql, array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $name = ltrim((string) $key, ':');
            $pattern = '/:' . preg_quote($name, '/') . '\b/';

            if (preg_match_all($pattern, $sql) < 2) {
                continue;
            }

            $occurrence = 0;
            $sql = (string) preg_replace_callback(
                $pattern,
                static function () use (&$occurrence, &$params, $name, $value): string {
                    $occurrence++;

                    if ($occurrence === 1) {
                        return ':' . $name;
                    }

                    $alias = $name . '__r' . $occurrence;
                    $params[$alias] = $value;

                    return ':' . $alias;
                },
                $sql
            );
        }

        return [$sql, $params];
    }

    public static function select(string $sql, array $params = []): array
    {
        return self::statement($sql, $params)->fetchAll();
    }

    public static function selectOne(string $sql, array $params = []): ?array
    {
        $row = self::statement($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::statement($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        self::statement($sql, $data);

        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $assignments = [];
        $params = [];

        foreach ($data as $column => $value) {
            $assignments[] = sprintf('`%s` = :set_%s', $column, $column);
            $params['set_' . $column] = $value;
        }

        foreach ($whereParams as $key => $value) {
            $params[ltrim((string) $key, ':')] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $assignments), $where);

        return self::statement($sql, $params)->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::statement(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $params)->rowCount();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
