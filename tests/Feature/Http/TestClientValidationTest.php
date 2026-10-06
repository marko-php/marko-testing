<?php

declare(strict_types=1);

use Marko\Testing\Http\TestClient;

use function Marko\Testing\Tests\httpAppPath;
use function Marko\Testing\Tests\removeHttpAppSessions;

function galleryFixtureDirectory(): string
{
    return sys_get_temp_dir() . '/marko-test-client-gallery-' . getmypid();
}

/**
 * Write $contents to a temporary file named $filename, in a directory removed after this file's tests.
 */
function galleryFixture(
    string $filename,
    string $contents,
): string {
    $directory = galleryFixtureDirectory();

    if (!is_dir($directory)) {
        mkdir($directory);
    }

    $path = $directory . '/' . $filename;
    file_put_contents($path, $contents);

    return $path;
}

/**
 * A 1x1 PNG, so the image rule sniffs a genuine image.
 */
function galleryPng(
    string $filename,
): string {
    return galleryFixture(
        $filename,
        (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
        ),
    );
}

afterAll(function (): void {
    removeHttpAppSessions();

    $directory = galleryFixtureDirectory();

    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
});

describe('TestClient multi-file upload validation', function (): void {
    it('returns 422 with an error for each invalid photo keyed by index', function (): void {
        TestClient::boot(httpAppPath())
            ->withHeader('Accept', 'application/json')
            ->withFile('photos[]', galleryPng('one.png'))
            ->withFile('photos[]', galleryFixture('notes.png', 'just some text'), 'notes.png', 'image/png')
            ->withFile('photos[]', galleryPng('two.png'))
            ->withFile('photos[]', galleryFixture('big.txt', str_repeat('a', 2048)))
            ->post('/gallery')
            ->assertUnprocessable()
            ->assertJsonPath('errors', [
                'photos.1' => ['The photos.1 field must be an image (JPEG, PNG, GIF or WebP).'],
                'photos.3' => [
                    'The photos.3 field must be an image (JPEG, PNG, GIF or WebP).',
                    'The photos.3 field must not be larger than 1 kilobytes.',
                ],
            ]);
    });

    it('accepts a multi-file upload when every photo is valid', function (): void {
        TestClient::boot(httpAppPath())
            ->withFiles('photos', [galleryPng('a.png'), galleryPng('b.png')])
            ->post('/gallery')
            ->assertOk()
            ->assertSee('stored 2 photos');
    });
});
