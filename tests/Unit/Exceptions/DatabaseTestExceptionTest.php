<?php

declare(strict_types=1);

use Marko\Testing\Exceptions\DatabaseTestException;

describe('DatabaseTestException', function (): void {
    it('describes the testing-only allowlist in the destructive environment exception', function (): void {
        $exception = DatabaseTestException::destructiveInEnvironment('truncate the entity tables', 'staging');

        expect($exception->getMessage())
            ->toBe("Refusing to truncate the entity tables in the 'staging' environment.")
            ->and($exception->getContext())
            ->toBe('This operation deletes data. It runs only when APP_ENV is a testing environment (testing, test).')
            ->and($exception->getSuggestion())
            ->toContain('APP_ENV=testing')
            ->toContain('MARKO_ENV')
            ->toContain('dedicated test database');
    });
});
