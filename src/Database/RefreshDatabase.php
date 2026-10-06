<?php

declare(strict_types=1);

namespace Marko\Testing\Database;

use Marko\Core\Contracts\ResettableInterface;
use Marko\Database\Connection\PendingAfterCommitInterface;
use Marko\Testing\Exceptions\DatabaseTestException;
use Psr\Container\ContainerExceptionInterface;
use Throwable;

/**
 * Isolates each test in a transaction that is always rolled back.
 *
 * begin() opens a transaction on the shared TransactionInterface before the
 * test; rollback() undoes everything the test wrote. Every repository, query
 * builder and service shares that one connection, so all their writes are
 * covered. Code under test that calls transaction() gets a savepoint and
 * behaves exactly as in production.
 *
 * The outer transaction never commits, so afterCommit() callbacks queued
 * during the test do not run on their own; call runAfterCommitCallbacks() to
 * run them. afterRollback() callbacks registered by code under test run when
 * the test transaction rolls back.
 *
 * ```php
 * beforeEach(function () {
 *     $this->database = TestDatabase::boot(dirname(__DIR__));
 *     $this->refresh = new RefreshDatabase($this->database);
 *     $this->refresh->begin();
 * });
 *
 * afterEach(function () {
 *     $this->refresh->rollback();
 * });
 * ```
 */
readonly class RefreshDatabase
{
    public function __construct(
        private TestDatabase $database,
    ) {}

    /**
     * Open the test transaction.
     *
     * @throws DatabaseTestException|ContainerExceptionInterface
     */
    public function begin(): void
    {
        $this->database->assertNotProduction();
        $transaction = $this->database->transaction();
        $level = $transaction->transactionLevel();

        if ($level > 0) {
            throw DatabaseTestException::testTransactionAlreadyOpen($level);
        }

        $transaction->beginTransaction();
    }

    /**
     * Roll back the test transaction, including any savepoints the code under
     * test left open. If a rollback fails (a dropped connection, say), the
     * connection is reset so the next test can begin, and the error is rethrown.
     *
     * @throws Throwable
     */
    public function rollback(): void
    {
        $transaction = $this->database->transaction();

        try {
            while ($transaction->transactionLevel() > 0) {
                $transaction->rollback();
            }
        } catch (Throwable $e) {
            if ($transaction instanceof ResettableInterface) {
                $transaction->reset();
            }

            throw $e;
        }
    }

    /**
     * Run the afterCommit() callbacks the code under test has queued so far,
     * as though the test transaction had committed. Nothing is committed.
     *
     * @throws DatabaseTestException|ContainerExceptionInterface
     */
    public function runAfterCommitCallbacks(): void
    {
        $transaction = $this->database->transaction();

        if ($transaction->transactionLevel() === 0) {
            throw DatabaseTestException::noTestTransaction('runAfterCommitCallbacks');
        }

        if (!$transaction instanceof PendingAfterCommitInterface) {
            throw DatabaseTestException::cannotRunPendingAfterCommitCallbacks($transaction::class);
        }

        $transaction->runPendingAfterCommitCallbacks();
    }

    public function database(): TestDatabase
    {
        return $this->database;
    }
}
