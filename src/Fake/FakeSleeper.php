<?php

declare(strict_types=1);

namespace Marko\Testing\Fake;

use Marko\Database\Connection\SleeperInterface;
use Marko\Testing\Exceptions\AssertionFailedException;

/**
 * Records sleeps instead of pausing, so tests assert the delays between
 * transaction() retry attempts without waiting for them. Requires
 * marko/database.
 */
class FakeSleeper implements SleeperInterface
{
    /** @var list<int> Milliseconds, in the order requested */
    public array $sleeps = [];

    public function sleep(
        int $milliseconds,
    ): void {
        $this->sleeps[] = $milliseconds;
    }

    public function clear(): void
    {
        $this->sleeps = [];
    }

    /**
     * @throws AssertionFailedException
     */
    public function assertSlept(
        int ...$milliseconds,
    ): void {
        $expected = array_values($milliseconds);

        if ($expected === $this->sleeps) {
            return;
        }

        $expectedText = $this->format($expected);
        $actualText = $this->format($this->sleeps);

        throw new AssertionFailedException(
            message: "Expected sleeps of $expectedText ms but slept $actualText ms.",
            context: "Expected: $expectedText, Actual: $actualText",
            suggestion: 'Each retry of transaction() sleeps once, after the failed attempt and before the next one.',
        );
    }

    /**
     * @throws AssertionFailedException
     */
    public function assertNotSlept(): void
    {
        if ($this->sleeps === []) {
            return;
        }

        $actualText = $this->format($this->sleeps);

        throw new AssertionFailedException(
            message: "Expected no sleeps but slept $actualText ms.",
            context: "Actual: $actualText",
            suggestion: 'transaction() sleeps only between retries of the outermost transaction, when attempts is above 1.',
        );
    }

    /**
     * @param list<int> $milliseconds
     */
    private function format(
        array $milliseconds,
    ): string {
        return '[' . implode(', ', $milliseconds) . ']';
    }
}
