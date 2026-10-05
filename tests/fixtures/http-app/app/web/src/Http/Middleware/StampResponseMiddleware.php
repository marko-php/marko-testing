<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Middleware;

use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\Middleware\MiddlewareInterface;

/**
 * Global middleware: proves a TestClient request runs the global middleware stack.
 */
class StampResponseMiddleware implements MiddlewareInterface
{
    public function handle(
        Request $request,
        callable $next,
    ): Response {
        return $next($request)->withHeader('X-Global-Middleware', 'applied');
    }
}
