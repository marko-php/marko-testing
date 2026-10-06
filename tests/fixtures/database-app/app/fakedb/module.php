<?php

declare(strict_types=1);

use Marko\Core\Container\ContainerInterface;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\TransactionInterface;
use Marko\Database\Introspection\IntrospectorInterface;
use Marko\Testing\Tests\DatabaseApp\RecordingConnection;
use Marko\Testing\Tests\DatabaseApp\RecordingIntrospector;

// Wired like a real driver: one shared connection that is also the
// TransactionInterface, so the helpers see exactly what a driver gives them.
return [
    'bindings' => [
        ConnectionInterface::class => RecordingConnection::class,
        IntrospectorInterface::class => RecordingIntrospector::class,
        TransactionInterface::class => static fn (ContainerInterface $container): TransactionInterface => $container->get(
            ConnectionInterface::class,
        ),
    ],
    'singletons' => [
        ConnectionInterface::class,
        IntrospectorInterface::class,
    ],
];
