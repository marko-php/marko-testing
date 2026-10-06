<?php

declare(strict_types=1);

use Marko\Core\Application;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\TransactionInterface;
use Marko\Testing\Database\TestDatabase;
use Marko\Testing\Exceptions\DatabaseTestException;
use Marko\Testing\Http\TestClient;
use Marko\Testing\Tests\DatabaseApp\RecordingConnection;

use function Marko\Testing\Tests\databaseAppPath;
use function Marko\Testing\Tests\httpAppPath;
use function Marko\Testing\Tests\removeHttpAppSessions;
use function Marko\Testing\Tests\withAppEnv;

class TestDatabaseWithoutDatabasePackage extends TestDatabase
{
    protected const string CONNECTION_INTERFACE = 'Marko\Database\Missing\ConnectionInterface';
}

afterAll(function (): void {
    removeHttpAppSessions();
});

describe('TestDatabase', function (): void {
    it('throws a clear exception when marko/database is not installed', function (): void {
        expect(fn () => TestDatabaseWithoutDatabasePackage::boot(databaseAppPath()))
            ->toThrow(DatabaseTestException::class, 'need marko/database');
    });

    it('throws a clear exception when no database driver is installed', function (): void {
        withAppEnv('testing', fn () => TestDatabase::boot(httpAppPath()));
    })->throws(DatabaseTestException::class, 'No database driver is installed');

    it('refuses to run in the production environment', function (): void {
        withAppEnv('production', fn () => TestDatabase::boot(databaseAppPath()));
    })->throws(DatabaseTestException::class, "Refusing to run database tests in the 'production' environment");

    it('treats an unset APP_ENV as production', function (): void {
        withAppEnv(null, fn () => TestDatabase::boot(databaseAppPath()));
    })->throws(DatabaseTestException::class, 'production');

    it('refuses production before touching the database', function (): void {
        $application = Application::boot(databaseAppPath());
        $database = new TestDatabase($application);

        try {
            withAppEnv('production', fn () => $database->assertNotProduction());
        } catch (DatabaseTestException) {
            // Expected.
        }

        expect($application->container->resolvedInstances(ConnectionInterface::class))->toBeEmpty();
    });

    it('migrates on the first boot and reuses the booted application for the same base path', function (): void {
        [$first, $second] = withAppEnv('testing', fn () => [
            TestDatabase::boot(databaseAppPath()),
            TestDatabase::boot(databaseAppPath() . '/../database-app'),
        ]);

        /** @var RecordingConnection $connection */
        $connection = $first->connection();
        $createTable = array_filter(
            $connection->statements,
            fn (string $sql): bool => str_contains($sql, 'CREATE TABLE IF NOT EXISTS "migrations"'),
        );

        expect($second)->toBe($first)
            ->and($first->application())->toBeInstanceOf(Application::class)
            ->and($createTable)->toHaveCount(1)
            ->and($first->appliedMigrations())->toBeEmpty();
    });

    it('checks the environment again when the booted application is reused', function (): void {
        withAppEnv('testing', fn () => TestDatabase::boot(databaseAppPath()));

        withAppEnv('production', fn () => TestDatabase::boot(databaseAppPath()));
    })->throws(DatabaseTestException::class, 'production');

    it('throws when the same base path is booted with a different fresh value', function (): void {
        withAppEnv('testing', fn () => TestDatabase::boot(databaseAppPath()));

        withAppEnv('testing', fn () => TestDatabase::boot(databaseAppPath(), fresh: true));
    })->throws(DatabaseTestException::class, 'fresh: true');

    it('allows destructive operations in the testing environments', function (string $environment): void {
        $database = new TestDatabase(Application::boot(databaseAppPath()));

        expect(fn () => withAppEnv($environment, fn () => $database->assertDisposable('rebuild the database')))
            ->not->toThrow(DatabaseTestException::class);
    })->with([
        'testing' => ['testing'],
        'test' => ['test'],
    ]);

    it('refuses destructive operations outside the testing environments', function (
        ?string $environment,
        string $name,
    ): void {
        $database = new TestDatabase(Application::boot(databaseAppPath()));

        expect(fn () => withAppEnv($environment, fn () => $database->assertDisposable('rebuild the database')))
            ->toThrow(DatabaseTestException::class, "Refusing to rebuild the database in the '$name' environment");
    })->with([
        'production' => ['production', 'production'],
        'unset' => [null, 'production'],
        'local' => ['local', 'local'],
        'development' => ['development', 'development'],
        'staging' => ['staging', 'staging'],
        'qa' => ['qa', 'qa'],
    ]);

    it('refuses to rebuild the database with fresh: true in staging before running any SQL', function (): void {
        $booted = new ReflectionProperty(TestDatabase::class, 'booted');
        $saved = $booted->getValue();
        $booted->setValue(null, []);
        RecordingConnection::$allStatements = [];

        try {
            expect(fn () => withAppEnv('staging', fn () => TestDatabase::boot(databaseAppPath(), fresh: true)))
                ->toThrow(
                    DatabaseTestException::class,
                    "Refusing to rebuild the database (fresh: true) in the 'staging' environment",
                )
                ->and(RecordingConnection::$allStatements)->toBeEmpty()
                ->and($booted->getValue())->toBeEmpty();
        } finally {
            $booted->setValue(null, $saved);
        }
    });

    it('exposes the shared connection as the transaction', function (): void {
        $database = new TestDatabase(Application::boot(databaseAppPath()));

        expect($database->transaction())->toBe($database->connection())
            ->and($database->transaction())->toBeInstanceOf(TransactionInterface::class);
    });

    it('returns a TestClient that does not reset the connection', function (): void {
        $database = new TestDatabase(Application::boot(databaseAppPath()));
        /** @var RecordingConnection $connection */
        $connection = $database->connection();

        $client = $database->client();
        $client->get('/missing');

        expect($client)->toBeInstanceOf(TestClient::class)
            ->and($connection->resets)->toBe(0);
    });

    it('seeds a table and counts its rows through the shared connection', function (): void {
        $database = new TestDatabase(Application::boot(databaseAppPath()));
        /** @var RecordingConnection $connection */
        $connection = $database->connection();
        $connection->results['SELECT COUNT(*) as count FROM "shows"'] = [['count' => 2]];

        $database->seedTable('shows', [['title' => 'One'], ['title' => 'Two']]);

        expect($database->getTableRowCount('shows'))->toBe(2)
            ->and($connection->statements)->toContain('INSERT INTO "shows" ("title") VALUES (?)');
    });
});
