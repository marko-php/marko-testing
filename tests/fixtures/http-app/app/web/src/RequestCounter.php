<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp;

use Marko\Core\Contracts\ResettableInterface;

/**
 * Request-scoped state held by a singleton: without a reset between
 * requests, the count would carry over from one request to the next.
 */
class RequestCounter implements ResettableInterface
{
    private int $count = 0;

    public function increment(): int
    {
        return ++$this->count;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}
