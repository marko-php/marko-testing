<?php

declare(strict_types=1);

use Marko\Testing\Exceptions\AssertionFailedException;
use Marko\Testing\Fake\FakeClock;
use Psr\Clock\ClockInterface;

it('implements the PSR-20 clock interface', function (): void {
    expect(new FakeClock())->toBeInstanceOf(ClockInterface::class);
});

it('returns the same fixed time on every call', function (): void {
    $clock = new FakeClock();

    $first = $clock->now();
    usleep(1000);

    expect($clock->now())->toEqual($first);
});

it('accepts a DateTimeImmutable or a date string', function (): void {
    $instant = new DateTimeImmutable('2026-01-01 12:00:00 UTC');

    expect(new FakeClock($instant)->now())->toEqual($instant)
        ->and(new FakeClock('2026-01-01 12:00:00 UTC')->now())->toEqual($instant);
});

it('moves time forward with travel', function (): void {
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');

    $clock->travel('+5 minutes');

    expect($clock->now())->toEqual(new DateTimeImmutable('2026-01-01 12:05:00 UTC'));
});

it('moves time backward with travel', function (): void {
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');

    $clock->travel('-1 day');

    expect($clock->now())->toEqual(new DateTimeImmutable('2025-12-31 12:00:00 UTC'));
});

it('jumps to an absolute time with travelTo', function (): void {
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');

    $clock->travelTo('2030-06-15 08:30:00 UTC');

    expect($clock->now())->toEqual(new DateTimeImmutable('2030-06-15 08:30:00 UTC'));
});

it('replaces the current time with setNow', function (): void {
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');
    $instant = new DateTimeImmutable('2027-03-03 03:03:03 UTC');

    $clock->setNow($instant);

    expect($clock->now())->toBe($instant);
});

it('passes assertNowIs when the time matches', function (): void {
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');
    $clock->travel('+1 hour');

    $clock->assertNowIs('2026-01-01 13:00:00 UTC');

    expect(true)->toBeTrue();
});

it('fails assertNowIs when the time differs', function (): void {
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');

    $clock->assertNowIs('2026-01-01 12:00:01 UTC');
})->throws(
    AssertionFailedException::class,
    'Expected the clock to read 2026-01-01T12:00:01.000000+00:00 but it reads 2026-01-01T12:00:00.000000+00:00.',
);

it('throws on a malformed travel modifier', function (): void {
    new FakeClock()->travel('not a modifier');
})->throws(DateMalformedStringException::class);
