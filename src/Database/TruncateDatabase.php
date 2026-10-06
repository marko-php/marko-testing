<?php

declare(strict_types=1);

namespace Marko\Testing\Database;

use Marko\Core\Path\ProjectPaths;
use Marko\Database\Entity\EntityDiscovery;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Exceptions\EntityException;
use Marko\Database\Exceptions\InvalidColumnException;
use Marko\Database\Introspection\IntrospectorInterface;
use Marko\Database\Query\IdentifierValidator;
use Marko\Testing\Exceptions\DatabaseTestException;
use Psr\Container\ContainerExceptionInterface;
use ReflectionException;
use Throwable;

/**
 * Empties every table that belongs to an entity, for tests whose code must
 * see committed data: a queue worker, a second process, or code that commits
 * on purpose. RefreshDatabase is faster; use this only where a rolled-back
 * transaction cannot work.
 *
 * Only tables of discovered #[Table] entities that exist are truncated. The
 * migrations table and tables created only by hand-written migrations are
 * left alone. Identity sequences restart. Runs only in a testing
 * environment (testing, test), and never inside an open transaction.
 *
 * ```php
 * beforeEach(function () {
 *     $this->database = TestDatabase::boot(dirname(__DIR__));
 *     new TruncateDatabase($this->database)->truncate();
 * });
 * ```
 */
readonly class TruncateDatabase
{
    private const string MIGRATIONS_TABLE = 'migrations';

    public function __construct(
        private TestDatabase $database,
    ) {}

    /**
     * Truncate every entity table.
     *
     * @throws DatabaseTestException|ContainerExceptionInterface|EntityException|ReflectionException|InvalidColumnException|Throwable
     */
    public function truncate(): void
    {
        $this->database->assertDisposable('truncate the entity tables');

        $level = $this->database->transaction()->transactionLevel();

        if ($level > 0) {
            throw DatabaseTestException::truncateInsideTransaction($level);
        }

        $tables = $this->tables();

        if ($tables === []) {
            return;
        }

        $connection = $this->database->connection();
        $driver = $connection->driverName();

        match ($driver) {
            'pgsql' => $connection->execute(
                'TRUNCATE TABLE ' . implode(', ', array_map(
                    static fn (string $table): string => '"' . $table . '"',
                    $tables,
                )) . ' RESTART IDENTITY CASCADE',
            ),
            'mysql' => $this->truncateMySql($tables),
            default => throw DatabaseTestException::unsupportedDriver($driver),
        };
    }

    /**
     * The tables truncate() empties: those of discovered entities (extenders
     * share their parent's table) that exist, never the migrations table.
     *
     * @return list<string>
     * @throws ContainerExceptionInterface|EntityException|ReflectionException|InvalidColumnException
     */
    public function tables(): array
    {
        $container = $this->database->application()->container;
        $paths = $container->get(ProjectPaths::class);
        $metadataFactory = $container->get(EntityMetadataFactory::class);
        $existing = $container->get(IntrospectorInterface::class)->getTables();

        $tables = [];

        foreach ($container->get(EntityDiscovery::class)->discoverAll(
            $paths->vendor,
            $paths->modules,
            $paths->app,
        ) as $entityClass) {
            $metadata = $metadataFactory->parse($entityClass);

            if ($metadata->isExtender()) {
                continue;
            }

            $table = $metadata->tableName;

            if ($table === self::MIGRATIONS_TABLE || !in_array($table, $existing, true)) {
                continue;
            }

            IdentifierValidator::assertValidIdentifier($table);
            $tables[$table] = $table;
        }

        sort($tables);

        return array_values($tables);
    }

    /**
     * @param list<string> $tables
     * @throws ContainerExceptionInterface|Throwable
     */
    private function truncateMySql(
        array $tables,
    ): int {
        $connection = $this->database->connection();
        $connection->execute('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $connection->execute('TRUNCATE TABLE `' . $table . '`');
            }
        } finally {
            $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return count($tables);
    }
}
