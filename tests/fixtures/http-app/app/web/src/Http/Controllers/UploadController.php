<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Controllers;

use JsonException;
use Marko\Routing\Attributes\Post;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\Http\UploadedFile;

/**
 * Echoes the shape of the uploaded files, so the tests can assert on nested and multi-file fields.
 *
 * @noinspection PhpUnused Route actions are invoked via reflection by the Router.
 */
class UploadController
{
    /**
     * @throws JsonException
     */
    #[Post('/uploads')]
    public function uploads(
        Request $request,
    ): Response {
        return Response::json([
            'tree' => self::describe($request->files()),
            'photos' => array_map(
                static fn (UploadedFile $file): string => $file->clientFilename() . ':' . $file->contents(),
                $request->files('photos'),
            ),
            'passport' => $request->file('documents.passport')?->clientFilename(),
            'temp_paths' => self::tempPaths($request->files()),
        ]);
    }

    /**
     * @param array<mixed> $files
     * @return list<string>
     */
    private static function tempPaths(
        array $files,
    ): array {
        $paths = [];

        array_walk_recursive($files, static function (mixed $file) use (&$paths): void {
            if ($file instanceof UploadedFile) {
                $paths[] = $file->tempPath();
            }
        });

        return $paths;
    }

    /**
     * @param array<mixed> $files
     * @return array<mixed>
     */
    private static function describe(
        array $files,
    ): array {
        return array_map(
            static fn (mixed $file): mixed => $file instanceof UploadedFile
                ? $file->clientFilename()
                : self::describe((array) $file),
            $files,
        );
    }
}
