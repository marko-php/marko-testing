<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\DatabaseApp;

use Marko\Core\Contracts\ResettableInterface;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\PendingAfterCommitInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\Connection\TransactionInterface;
use Marko\Database\Connection\TransactionState;
use Marko\Database\Exceptions\TransactionException;
use RuntimeException;
use Throwable;

/**
 * An in-memory driver connection that records every statement and tracks
 * transaction levels with the same TransactionState the real drivers use.
 */
class RecordingConnection implements ConnectionInterface, TransactionInterface, PendingAfterCommitInterface, ResettableInterface
{
    /** @var list<string> */
    public array $statements = [];

    public string $driver = 'pgsql';

    public bool $failRollback = false;

    public int $resets = 0;

    /** @var array<string, list<array<string, mixed>>> SQL => rows */
    public array $results = [];

    /** @var array<string, string> SQL fragment => exception message */
    public array $failures = [];

    private readonly TransactionState $transactionState;

    public function __construct()
    {
        $this->transactionState = new TransactionState();
    }

    public function connect(): void {}

    public function disconnect(): void {}

    public function isConnected(): bool
    {
        return true;
    }

    public function query(
        string $sql,
        array $bindings = [],
    ): array {
        $this->statements[] = $sql;

        return $this->results[$sql] ?? [];
    }

    /**
     * @throws RuntimeException
     */
    public function execute(
        string $sql,
        array $bindings = [],
    ): int {
        foreach ($this->failures as $fragment => $message) {
            if (str_contains($sql, $fragment)) {
                throw new RuntimeException($message);
            }
        }

        $this->statements[] = $sql;

        return 1;
    }

    /**
     * @throws RuntimeException
     */
    public function prepare(string $sql): StatementInterface
    {
        throw new RuntimeException('Not implemented');
    }

    public function lastInsertId(): int
    {
        return 0;
    }

    public function driverName(): string
    {
        return $this->driver;
    }

    public function beginTransaction(): void
    {
        $this->statements[] = $this->transactionState->level() === 0 ? 'BEGIN' : 'SAVEPOINT';
        $this->transactionState->begin();
    }

    /**
     * @throws TransactionException
     */
    public function commit(): void
    {
        if ($this->transactionState->level() === 0) {
            throw TransactionException::notInTransaction();
        }

        $this->statements[] = 'COMMIT';
        $this->transactionState->commit();
    }

    /**
     * @throws TransactionException|RuntimeException
     */
    public function rollback(): void
    {
        if ($this->transactionState->level() === 0) {
            throw TransactionException::notInTransaction();
        }

        if ($this->failRollback) {
            throw new RuntimeException('server closed the connection');
        }

        $this->statements[] = 'ROLLBACK';
        $this->transactionState->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->transactionState->level() > 0;
    }

    public function transactionLevel(): int
    {
        return $this->transactionState->level();
    }

    /**
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->rollback();

            throw $e;
        }

        $this->commit();

        return $result;
    }

    public function afterCommit(callable $callback): void
    {
        $this->transactionState->afterCommit($callback);
    }

    public function afterRollback(callable $callback): void
    {
        $this->transactionState->afterRollback($callback);
    }

    public function runPendingAfterCommitCallbacks(): void
    {
        $this->transactionState->runAfterCommitCallbacks();
    }

    public function reset(): void
    {
        $this->resets++;
        $this->transactionState->clear();
    }
}
