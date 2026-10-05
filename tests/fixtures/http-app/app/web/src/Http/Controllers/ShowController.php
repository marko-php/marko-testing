<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Controllers;

use JsonException;
use Marko\Routing\Attributes\Get;
use Marko\Routing\Attributes\Post;
use Marko\Routing\Exceptions\HttpException;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;

/**
 * JSON in and out.
 *
 * @noinspection PhpUnused Route actions are invoked via reflection by the Router.
 */
class ShowController
{
    /**
     * @throws JsonException|HttpException
     */
    #[Get('/api/shows/{id}')]
    public function show(
        int $id,
    ): Response {
        if ($id === 404) {
            throw HttpException::notFound('Show not found');
        }

        return Response::json([
            'data' => ['id' => $id, 'status' => 'live', 'tags' => ['music', 'live']],
        ]);
    }

    /**
     * @throws JsonException
     */
    #[Post('/api/shows/{id}/events')]
    public function recordEvent(
        int $id,
        Request $request,
    ): Response {
        return Response::json(
            ['data' => ['show' => $id, 'type' => $request->json('type')]],
            202,
        );
    }
}
