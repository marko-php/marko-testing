<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Controllers;

use Marko\Routing\Attributes\Post;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Validation\Contracts\ValidatorInterface;
use Marko\Validation\Exceptions\ValidationException;

/**
 * Validates a multi-file upload file by file, so the tests can assert on per-file error keys.
 *
 * @noinspection PhpUnused Route actions are invoked via reflection by the Router.
 */
class GalleryController
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * @throws ValidationException
     */
    #[Post('/gallery')]
    public function store(
        Request $request,
    ): Response {
        $this->validator->validateOrFail($request->files(), [
            'photos' => 'required|array|max:5',
            'photos.*' => 'image|max_size:1',
        ]);

        return new Response('stored ' . count($request->files('photos')) . ' photos');
    }
}
