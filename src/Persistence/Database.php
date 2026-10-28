<?php

declare(strict_types=1);

namespace App\Persistence;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper shared by every repository.
 *
 * It exists to do four things and nothing else: hold one lazily-opened
 * connection, run prepared statements, hide the two engines' differences
 * behind the Platform, and provide transactions. It contains no knowledge of
 * any table. Repositories are the only callers.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** How many statements this connection has run — for tests that bound a query count. */
    private int $statements = 0;

    /**
     * @param array{driver: string, host: string, port: int, name: string,
     *              user: string, password: string, charset: string} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly Platform $platform,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * How many statements have been run through this object. Read by tests
     * that assert a page's query count does not grow with its rows (K17);
     * nothing in the application acts on it.
     */
    public function statementCount(): int
    {
        return $this->statements;
    }

    public function platform(): Platform
    {
        return $this->platform;
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, never emulated: emulation re-introduces
            // string interpolation on the client side.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ] + $this->platform->connectionOptions();

        $pdo = new PDO(
            $this->platform->dsn([
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'name' => $this->config['name'],
                'charset' => $this->config['charset'],
            ]),
            $this->config['user'],
            $this->config['password'],
            $options,
        );

        $optional = $this->platform->optionalInitialStatements();
        foreach ($this->platform->initialStatements() as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $exception) {
                if (!in_array($statement, $optional, true)) {
                    throw $exception;
                }

                // One line per connection, and the application opens one.
                $this->logger?->debug('The server refused a session setting; carrying on without it.', [
                    'statement' => $statement,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $params
     * @return int Number of affected rows.
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * One statement, prepared once and run as many times as it is called —
     * for a loop that runs the same SQL for every row (K112: a restore's
     * INSERTs). Each call binds its own values exactly as `execute()` does.
     *
     * @return \Closure(array<string, mixed>): int Runs it; returns the rows affected.
     */
    public function prepared(string $sql): \Closure
    {
        $statement = $this->connection()->prepare($sql);

        return function (array $params) use ($statement): int {
            $this->statements++;
            foreach ($params as $name => $value) {
                $statement->bindValue(
                    ':' . ltrim((string) $name, ':'),
                    $this->normaliseParameter($value),
                    $this->parameterType($value),
                );
            }
            $statement->execute();

            return $statement->rowCount();
        };
    }

    /**
     * Insert a row and return its generated identifier.
     *
     * @param array<string, mixed> $data Column name => value.
     */
    public function insert(string $table, array $data, string $idColumn = 'id'): int
    {
        $columns = array_keys($data);
        $quoted = array_map($this->platform->quoteIdentifier(...), $columns);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->platform->quoteIdentifier($table),
            implode(', ', $quoted),
            implode(', ', $placeholders),
        );

        if ($this->platform->supportsInsertReturning()) {
            $sql .= ' RETURNING ' . $this->platform->quoteIdentifier($idColumn);
            $id = $this->run($sql, $data)->fetchColumn();

            return (int) $id;
        }

        $this->run($sql, $data);

        return (int) $this->connection()->lastInsertId(
            $this->platform->insertIdSequence($table, $idColumn),
        );
    }

    /**
     * Whether a failed statement failed because it collided with a unique
     * index.
     *
     * Asked by the code that claims an idempotency row: the claim is an INSERT
     * that is *expected* to fail when the same notification has already been
     * claimed, and the collision is the answer rather than an error. Both
     * engines report it in SQLSTATE class 23 — PostgreSQL as 23505, MySQL as
     * the less specific 23000 — so the check covers both rather than the one
     * the developer happened to be running.
     *
     * Note for callers: on PostgreSQL a failed statement poisons an open
     * transaction, so a claim must be made outside one.
     */
    public static function isUniqueViolation(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23505', '23000'], true);
    }

    /**
     * Run a callable inside a transaction, rolling back on any throwable.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $pdo = $this->connection();

        if ($pdo->inTransaction()) {
            // Nested calls join the outer transaction rather than silently
            // committing part of it.
            return $work($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $work($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->rollBackIfOpen($pdo);

            throw $exception;
        }
    }

    /**
     * Run `$work` so that a statement it fails on can be caught and the
     * transaction carried on.
     *
     * PostgreSQL aborts the whole transaction on a failed statement, so a
     * caller that expects a unique violation and handles it (two requests
     * creating one tag) must put a savepoint round it. Outside a transaction
     * there is nothing to protect and `$work` simply runs.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function withSavepoint(callable $work): mixed
    {
        $pdo = $this->connection();

        if (!$pdo->inTransaction()) {
            return $work();
        }

        $pdo->exec('SAVEPOINT renovo_attempt');

        try {
            $result = $work();
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK TO SAVEPOINT renovo_attempt');
            $pdo->exec('RELEASE SAVEPOINT renovo_attempt');

            throw $exception;
        }

        $pdo->exec('RELEASE SAVEPOINT renovo_attempt');

        return $result;
    }

    /**
     * Run `$work` inside a read-only transaction that sees one consistent
     * snapshot of every table (Platform::snapshotStatements()).
     *
     * It must be the outermost transaction: inside another one the isolation
     * level can no longer be chosen, and the reads would see whatever that
     * transaction had already seen.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function snapshot(callable $work): mixed
    {
        $pdo = $this->connection();

        if ($pdo->inTransaction()) {
            throw new \LogicException('A snapshot cannot be taken inside another transaction.');
        }

        foreach ($this->platform->snapshotStatements() as $statement) {
            $pdo->exec($statement);
        }

        try {
            $result = $work($this);
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');

            throw $exception;
        }

        $pdo->exec('COMMIT');

        return $result;
    }

    /**
     * Roll back only if a transaction is still open.
     *
     * The check is not redundant: MySQL performs an implicit commit on DDL, so
     * by the time an exception surfaces the transaction may already be gone,
     * and rolling back then would itself throw.
     */
    private function rollBackIfOpen(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $this->statements++;
        $statement = $this->connection()->prepare($sql);

        foreach ($params as $name => $value) {
            $statement->bindValue(
                ':' . ltrim($name, ':'),
                $this->normaliseParameter($value),
                $this->parameterType($value),
            );
        }

        $statement->execute();

        return $statement;
    }

    private function normaliseParameter(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $this->platform->booleanParameter($value);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function parameterType(mixed $value): int
    {
        if (is_bool($value)) {
            return $this->platform->supportsInsertReturning() ? PDO::PARAM_BOOL : PDO::PARAM_INT;
        }

        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }
}
