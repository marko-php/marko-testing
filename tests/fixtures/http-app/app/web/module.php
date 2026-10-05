<?php

declare(strict_types=1);

use Marko\Authentication\Contracts\UserProviderInterface;
use Marko\Testing\Fake\FakeAuthenticatable;
use Marko\Testing\Fake\FakeUserProvider;
use Marko\Testing\Tests\HttpApp\Http\Middleware\StampResponseMiddleware;
use Marko\Testing\Tests\HttpApp\RequestCounter;

return [
    'bindings' => [
        UserProviderInterface::class => static fn (): UserProviderInterface => new FakeUserProvider(
            users: [1 => new FakeAuthenticatable(id: 1)],
        ),
    ],
    'singletons' => [
        RequestCounter::class,
    ],
    'globalMiddleware' => [
        StampResponseMiddleware::class,
    ],
];
