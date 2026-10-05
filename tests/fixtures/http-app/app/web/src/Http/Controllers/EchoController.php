<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Controllers;

use JsonException;
use Marko\Routing\Attributes\Delete;
use Marko\Routing\Attributes\Get;
use Marko\Routing\Attributes\Patch;
use Marko\Routing\Attributes\Post;
use Marko\Routing\Attributes\Put;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\Http\UploadedFile;
use Marko\Testing\Tests\HttpApp\RequestCounter;
use Marko\Testing\Tests\HttpApp\Routing\Head;
use Marko\Testing\Tests\HttpApp\Routing\Options;
use RuntimeException;

/**
 * Echoes back what the controller received, so the tests can assert on the
 * Request the TestClient built.
 *
 * @noinspection PhpUnused Route actions are invoked via reflection by the Router.
 */
class EchoController
{
    public function __construct(
        private readonly RequestCounter $counter,
    ) {}

    /**
     * @throws JsonException
     */
    #[Get('/echo')]
    #[Post('/echo')]
    #[Put('/echo')]
    #[Patch('/echo')]
    #[Delete('/echo')]
    #[Options('/echo')]
    #[Head('/echo')]
    public function echo(
        Request $request,
    ): Response {
        $files = array_map(
            static fn (UploadedFile $file): array => [
                'client_filename' => $file->clientFilename(),
                'client_media_type' => $file->clientMediaType(),
                'size' => $file->size(),
                'contents' => $file->contents(),
            ],
            array_filter($request->files(), static fn (mixed $file): bool => $file instanceof UploadedFile),
        );

        return Response::json([
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query(),
            'post' => $request->post(),
            'json' => $request->isJson() ? $request->json() : null,
            'body' => $request->body(),
            'cookies' => $request->cookie(),
            'files' => $files,
            'server' => [
                'REQUEST_METHOD' => $request->server('REQUEST_METHOD'),
                'REQUEST_URI' => $request->server('REQUEST_URI'),
                'QUERY_STRING' => $request->server('QUERY_STRING'),
                'REMOTE_ADDR' => $request->server('REMOTE_ADDR'),
                'CONTENT_TYPE' => $request->server('CONTENT_TYPE'),
                'CONTENT_LENGTH' => $request->server('CONTENT_LENGTH'),
                'HTTP_ACCEPT' => $request->server('HTTP_ACCEPT'),
                'HTTP_X_REQUEST_ID' => $request->server('HTTP_X_REQUEST_ID'),
                'HTTP_HOST' => $request->server('HTTP_HOST'),
                'HTTP_COOKIE' => $request->server('HTTP_COOKIE'),
                'SERVER_NAME' => $request->server('SERVER_NAME'),
            ],
        ]);
    }

    #[Get('/counter')]
    public function counter(): Response
    {
        return new Response('count=' . $this->counter->increment());
    }

    /**
     * @throws RuntimeException
     */
    #[Get('/explode')]
    public function explode(): Response
    {
        throw new RuntimeException('Controller exploded');
    }
}
