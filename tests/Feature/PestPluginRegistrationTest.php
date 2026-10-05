<?php

declare(strict_types=1);

/**
 * Runs Pest in a subprocess against tests/fixtures/pest-project, whose Pest.php
 * contains no require. The subprocess uses the monorepo's Composer install, where
 * marko/testing is a real dependency in vendor/ (path repository), so Composer's
 * autoload.files order and pestphp/pest-plugin's generated vendor/pest-plugins.json
 * are exactly what a user project gets.
 *
 * @return array{exitCode: int, output: string}
 */
function runFixturePestProject(): array
{
    $root = dirname(__DIR__, 4);
    $fixture = 'packages/testing/tests/fixtures/pest-project';

    $command = [
        PHP_BINARY,
        $root . '/vendor/bin/pest',
        '--configuration=' . $fixture . '/phpunit.xml',
        '--test-directory=' . $fixture . '/tests',
        '--colors=never',
        // Keeps PHPUnit from writing .phpunit.result.cache into the fixture directory.
        '--cache-directory=' . sys_get_temp_dir() . '/marko-testing-pest-fixture-cache',
    ];

    // A clean environment keeps a parallel parent run (PARATEST, TEST_TOKEN, ...)
    // from leaking into the child Pest process.
    $environment = [
        'PATH' => (string) getenv('PATH'),
        'HOME' => (string) getenv('HOME'),
    ];

    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
        $environment,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start the fixture Pest process.');
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);

    return ['exitCode' => $exitCode, 'output' => $output];
}

it('registers toHavePushed in a Pest run whose Pest.php has no require', function (): void {
    $result = runFixturePestProject();

    expect($result['output'])->not->toContain('Call to undefined method Marko\Testing\Fake\FakeQueue::toHavePushed()')
        ->and($result['output'])->toContain('passes toHavePushed when the job was pushed')
        ->and($result['exitCode'])->toBe(0, $result['output']);
});

it('fails toHavePushed with an assertion failure when nothing was pushed', function (): void {
    $result = runFixturePestProject();

    expect($result['output'])->toContain('fails toHavePushed with an assertion failure when nothing was pushed')
        ->and($result['output'])->toContain('2 passed')
        ->and($result['exitCode'])->toBe(0, $result['output']);
});
