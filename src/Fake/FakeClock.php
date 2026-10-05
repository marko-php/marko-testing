<?php

declare(strict_types=1);

namespace Marko\Testing\Fake;

use DateMalformedStringException;
use DateTimeImmutable;
use Marko\Testing\Exceptions\AssertionFailedException;
use Psr\Clock\ClockInterface;

/**
 * A PSR-20 clock frozen at a fixed instant.
 *
 * Time never advances on its own; only setNow(), travel() and travelTo() move it.
 */
class FakeClock implements ClockInterface
{
    private DateTimeImmutable $now;

    /**
     * @throws DateMalformedStringException
     */
    public function __construct(
        DateTimeImmutable|string $now = 'now',
    ) {
        $this->now = $this->toDateTime($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * @throws DateMalformedStringException
     */
    public function setNow(
        DateTimeImmutable|string $now,
    ): void {
        $this->now = $this->toDateTime($now);
    }

    /**
     * Move the clock by a relative modifier, e.g. '+5 minutes' or '-1 day'.
     *
     * @throws DateMalformedStringException When the modifier cannot be parsed
     */
    public function travel(
        string $modifier,
    ): void {
        $this->now = $this->now->modify($modifier);
    }

    /**
     * @throws DateMalformedStringException
     */
    public function travelTo(
        DateTimeImmutable|string $now,
    ): void {
        $this->setNow($now);
    }

    /**
     * @throws AssertionFailedException|DateMalformedStringException
     */
    public function assertNowIs(
        DateTimeImmutable|string $expected,
    ): void {
        $expected = $this->toDateTime($expected);

        if ($expected == $this->now) {
            return;
        }

        $expectedText = $expected->format('Y-m-d\TH:i:s.uP');
        $actualText = $this->now->format('Y-m-d\TH:i:s.uP');

        throw new AssertionFailedException(
            message: "Expected the clock to read $expectedText but it reads $actualText.",
            context: "Expected: $expectedText, Actual: $actualText",
            suggestion: 'Move the clock with travel(), travelTo() or setNow() before asserting.',
        );
    }

    /**
     * @throws DateMalformedStringException
     */
    private function toDateTime(
        DateTimeImmutable|string $value,
    ): DateTimeImmutable {
        return $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable($value);
    }
}
