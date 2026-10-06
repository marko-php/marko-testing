<?php

declare(strict_types=1);

use Marko\Database\Connection\SleeperInterface;
use Marko\Testing\Exceptions\AssertionFailedException;
use Marko\Testing\Fake\FakeSleeper;

it('implements the database sleeper interface', function (): void {
    expect(new FakeSleeper())->toBeInstanceOf(SleeperInterface::class);
});

it('records each sleep in milliseconds without sleeping', function (): void {
    $sleeper = new FakeSleeper();
    $started = hrtime(true);

    $sleeper->sleep(5_000);
    $sleeper->sleep(0);

    expect($sleeper->sleeps)->toBe([5_000, 0])
        ->and((hrtime(true) - $started) / 1_000_000)->toBeLessThan(1_000.0);
});

it('passes assertSlept when the recorded delays match', function (): void {
    $sleeper = new FakeSleeper();
    $sleeper->sleep(10);
    $sleeper->sleep(20);

    $sleeper->assertSlept(10, 20);

    expect($sleeper->sleeps)->toBe([10, 20]);
});

it('fails assertSlept when the recorded delays differ', function (): void {
    $sleeper = new FakeSleeper();
    $sleeper->sleep(10);

    expect(fn () => $sleeper->assertSlept(10, 20))
        ->toThrow(AssertionFailedException::class, 'Expected sleeps of [10, 20] ms but slept [10] ms.');
});

it('passes assertNotSlept when nothing was recorded', function (): void {
    $sleeper = new FakeSleeper();

    $sleeper->assertNotSlept();

    expect($sleeper->sleeps)->toBe([]);
});

it('fails assertNotSlept after a sleep', function (): void {
    $sleeper = new FakeSleeper();
    $sleeper->sleep(0);

    expect(fn () => $sleeper->assertNotSlept())
        ->toThrow(AssertionFailedException::class, 'Expected no sleeps but slept [0] ms.');
});

it('forgets recorded sleeps on clear', function (): void {
    $sleeper = new FakeSleeper();
    $sleeper->sleep(10);

    $sleeper->clear();

    $sleeper->assertNotSlept();
    expect($sleeper->sleeps)->toBe([]);
});
