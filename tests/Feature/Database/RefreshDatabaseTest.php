<?php

declare(strict_types=1);

use Marko\Core\Application;
use Marko\Database\Connection\TransactionInterface;
use Marko\Testing\Database\RefreshDatabase;
use Marko\Testing\Database\TestDatabase;
use Marko\Testing\Exceptions\DatabaseTestException;
use Marko\Testing\Tests\DatabaseApp\RecordingConnection;

use function Marko\Testing\Tests\databaseAppPath;
use function Marko\Testing\Tests\withAppEnv;

/**
 * @return array{refresh: RefreshDatabase, connection: RecordingConnection, application: Application}
 */
function makeRefreshDatabase(): array
{
    $application = Application::boot(databaseAppPath());
    /** @var RecordingConnection $connection */
    $connection = $application->container->get(TransactionInterface::class);

    return [
        'refresh' => new RefreshDatabase(new TestDatabase($application)),
        'connection' => $connection,
        'application' => $application,
    ];
}

describe('RefreshDatabase', function (): void {
    it('opens a transaction on the shared connection', function (): void {
        ['refresh' => $refresh, 'connection' => $connection] = makeRefreshDatabase();

        withAppEnv('testing', fn () => $refresh->begin());

        expect($connection->transactionLevel())->toBe(1)
            ->and($connection->statements)->toBe(['BEGIN']);
    });

    it('refuses to begin in production', function (): void {
        ['refresh' => $refresh] = makeRefreshDatabase();

        withAppEnv('production', fn () => $refresh->begin());
    })->throws(DatabaseTestException::class, 'production');

    it('throws when a test transaction is already open', function (): void {
        ['refresh' => $refresh] = makeRefreshDatabase();

        withAppEnv('testing', function () use ($refresh): void {
            $refresh->begin();
            $refresh->begin();
        });
    })->throws(DatabaseTestException::class, 'A test transaction is already open (transaction level 1)');

    it('rolls back every open level', function (): void {
        ['refresh' => $refresh, 'connection' => $connection] = makeRefreshDatabase();

        withAppEnv('testing', fn () => $refresh->begin());
        $connection->beginTransaction();
        $connection->beginTransaction();
        $refresh->rollback();

        expect($connection->transactionLevel())->toBe(0)
            ->and(array_count_values($connection->statements)['ROLLBACK'])->toBe(3);
    });

    it('lets code under test commit its own nested transaction inside the test transaction', function (): void {
        ['refresh' => $refresh, 'connection' => $connection] = makeRefreshDatabase();

        withAppEnv('testing', fn () => $refresh->begin());
        $result = $connection->transaction(fn (): string => 'done');
        $levelAfterNested = $connection->transactionLevel();
        $refresh->rollback();

        expect($result)->toBe('done')
            ->and($levelAfterNested)->toBe(1)
            ->and($connection->statements)->toBe(['BEGIN', 'SAVEPOINT', 'COMMIT', 'ROLLBACK']);
    });

    it(
        'runs after-rollback callbacks registered by code under test when the test transaction rolls back',
        function (): void {
            ['refresh' => $refresh, 'connection' => $connection] = makeRefreshDatabase();
            $ran = false;

            withAppEnv('testing', fn () => $refresh->begin());
            $connection->afterRollback(function () use (&$ran): void {
                $ran = true;
            });
            $refresh->rollback();

            expect($ran)->toBeTrue();
        },
    );

    it('does not run after-commit callbacks when the test transaction rolls back', function (): void {
        ['refresh' => $refresh, 'connection' => $connection] = makeRefreshDatabase();
        $ran = false;

        withAppEnv('testing', fn () => $refresh->begin());
        $connection->afterCommit(function () use (&$ran): void {
            $ran = true;
        });
        $refresh->rollback();

        expect($ran)->toBeFalse();
    });

    it('runs queued after-commit callbacks on request', function (): void {
        ['refresh' => $refresh, 'connection' => $connection] = makeRefreshDatabase();
        $runs = 0;

        withAppEnv('testing', fn () => $refresh->begin());
        $connection->transaction(function () use ($connection, &$runs): void {
            $connection->afterCommit(function () use (&$runs): void {
                $runs++;
            });
        });
        $beforeRun = $runs;
        $refresh->runAfterCommitCallbacks();
        $refresh->rollback();

        expect($beforeRun)->toBe(0)
            ->and($runs)->toBe(1);
    });

    it('throws when runAfterCommitCallbacks is called without a test transaction', function (): void {
        ['refresh' => $refresh] = makeRefreshDatabase();

        $refresh->runAfterCommitCallbacks();
    })->throws(DatabaseTestException::class, 'Cannot runAfterCommitCallbacks: no test transaction is open');

    it('throws when the connection cannot run pending after-commit callbacks', function (): void {
        ['refresh' => $refresh, 'application' => $application] = makeRefreshDatabase();
        $application->container->instance(TransactionInterface::class, new class () implements TransactionInterface
        {
            public function beginTransaction(): void {}

            public function commit(): void {}

            public function rollback(): void {}

            public function inTransaction(): bool
            {
                return true;
            }

            public function transactionLevel(): int
            {
                return 1;
            }

            public function transaction(
                callable $callback,
                int $attempts = 1,
                int|Closure|null $backoff = null,
            ): mixed {
                return $callback();
            }

            public function afterCommit(callable $callback): void {}

            public function afterRollback(callable $callback): void {}
        });

        $refresh->runAfterCommitCallbacks();
    })->throws(DatabaseTestException::class, 'cannot run pending after-commit callbacks');

    it('resets the connection and rethrows when the rollback fails so the next test can begin', function (): void {
        ['refresh' => $refresh, 'connection' => $connection] = makeRefreshDatabase();

        withAppEnv('testing', fn () => $refresh->begin());
        $connection->failRollback = true;

        try {
            $refresh->rollback();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        $connection->failRollback = false;
        withAppEnv('testing', fn () => $refresh->begin());

        expect($error ?? null)->toBe('server closed the connection')
            ->and($connection->resets)->toBe(1)
            ->and($connection->transactionLevel())->toBe(1);
    });
});
