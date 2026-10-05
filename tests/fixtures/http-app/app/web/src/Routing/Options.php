<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Routing;

use Attribute;
use Marko\Routing\Attributes\Route;

/**
 * marko/routing ships no OPTIONS attribute; this fixture-local one lets the
 * tests route an OPTIONS request to a controller.
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class Options extends Route
{
    public function getMethod(): string
    {
        return 'OPTIONS';
    }
}
