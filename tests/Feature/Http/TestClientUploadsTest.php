<?php

declare(strict_types=1);

use Marko\Testing\Exceptions\TestClientException;
use Marko\Testing\Http\TestClient;

use function Marko\Testing\Tests\httpAppPath;
use function Marko\Testing\Tests\removeHttpAppSessions;

function uploadFixtureDirectory(): string
{
    return sys_get_temp_dir() . '/marko-test-client-uploads-' . getmypid();
}

/**
 * Create a temporary file holding $contents, in a directory removed after this file's tests.
 */
function uploadFixture(
    string $contents,
): string {
    $directory = uploadFixtureDirectory();

    if (!is_dir($directory)) {
        mkdir($directory);
    }

    $path = (string) tempnam($directory, 'upload-');
    file_put_contents($path, $contents);

    return $path;
}

afterAll(function (): void {
    removeHttpAppSessions();

    $directory = uploadFixtureDirectory();

    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
});

describe('TestClient multi-file uploads', function (): void {
    it('sends two photos[] uploads as a list under photos, in order', function (): void {
        TestClient::boot(httpAppPath())
            ->withFile('photos[]', uploadFixture('first'), 'one.txt')
            ->withFile('photos[]', uploadFixture('second'), 'two.txt')
            ->post('/uploads')
            ->assertOk()
            ->assertJsonPath('photos', ['one.txt:first', 'two.txt:second'])
            ->assertJsonPath('tree', ['photos' => ['one.txt', 'two.txt']]);
    });

    it('sends documents[passport] under the nested key', function (): void {
        TestClient::boot(httpAppPath())
            ->withFile('documents[passport]', uploadFixture('p'), 'passport.pdf')
            ->withFile('documents[visa]', uploadFixture('v'), 'visa.pdf')
            ->post('/uploads')
            ->assertJsonPath('passport', 'passport.pdf')
            ->assertJsonPath('tree', ['documents' => ['passport' => 'passport.pdf', 'visa' => 'visa.pdf']]);
    });

    it('nests list fields inside named fields', function (): void {
        TestClient::boot(httpAppPath())
            ->withFile('gallery[photos][]', uploadFixture('a'), 'a.txt')
            ->withFile('gallery[photos][]', uploadFixture('b'), 'b.txt')
            ->post('/uploads')
            ->assertJsonPath('tree', ['gallery' => ['photos' => ['a.txt', 'b.txt']]]);
    });

    it('sends a list of files with withFiles', function (): void {
        $first = uploadFixture('first');
        $second = uploadFixture('second');

        TestClient::boot(httpAppPath())
            ->withFiles('photos', [$first, $second])
            ->post('/uploads')
            ->assertJsonPath('photos', [basename($first) . ':first', basename($second) . ':second']);
    });

    it('throws when a non-array field is uploaded twice', function (): void {
        TestClient::boot(httpAppPath())
            ->withFile('avatar', uploadFixture('a'))
            ->withFile('avatar', uploadFixture('b'));
    })->throws(TestClientException::class, 'already has a file');

    it('throws when a field is used both as a file and as an array of files', function (): void {
        TestClient::boot(httpAppPath())
            ->withFile('photos', uploadFixture('a'))
            ->withFile('photos[]', uploadFixture('b'));
    })->throws(TestClientException::class, 'Cannot upload to [photos[]]');

    it('throws when a list field is later used as a single file', function (): void {
        TestClient::boot(httpAppPath())
            ->withFile('photos[]', uploadFixture('a'))
            ->withFile('photos', uploadFixture('b'));
    })->throws(TestClientException::class, 'Cannot upload to [photos]');

    it('throws for a malformed upload field name', function (string $field): void {
        TestClient::boot(httpAppPath())->withFile($field, uploadFixture('a'));
    })->with(['photos[', 'photos]', '[]', '', 'photos[a]b'])
        ->throws(TestClientException::class, 'is not a valid upload field');

    it('removes every temporary upload copy after the request', function (): void {
        $paths = TestClient::boot(httpAppPath())
            ->withFile('photos[]', uploadFixture('a'))
            ->withFile('documents[passport]', uploadFixture('b'))
            ->post('/uploads')
            ->json('temp_paths');

        expect($paths)->toHaveCount(2)
            ->and(array_filter($paths, is_file(...)))->toBe([]);
    });
});
