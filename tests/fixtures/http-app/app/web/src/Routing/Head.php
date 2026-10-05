<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Routing;

use Attribute;
use Marko\Routing\Attributes\Route;

/**
 * marko/routing ships no HEAD attribute; this fixture-local one lets the
 * tests route a HEAD request to a controller.
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class Head extends Route
{
    public function getMethod(): string
    {
        return 'HEAD';
    }
}
