<?php

declare(strict_types=1);

use Marko\Core\Application;
use Marko\Routing\Http\Response;
use Marko\Testing\Exceptions\TestClientException;
use Marko\Testing\Http\TestClient;
use Marko\Testing\Http\TestResponse;

use function Marko\Testing\Tests\httpAppPath;
use function Marko\Testing\Tests\removeHttpAppSessions;

afterAll(function (): void {
    removeHttpAppSessions();
});

describe('TestClient lifecycle', function (): void {
    it('boots the application at the base path and returns a TestResponse', function (): void {
        $client = TestClient::boot(httpAppPath());

        $response = $client->get('/api/shows/42');

        expect($response)->toBeInstanceOf(TestResponse::class)
            ->and($client->application())->toBeInstanceOf(Application::class);
        $response->assertOk()->assertJsonPath('data.status', 'live');
    });

    it('wraps an application that is already booted', function (): void {
        $application = Application::boot(httpAppPath());

        $client = TestClient::forApplication($application);

        expect($client->application())->toBe($application);
        $client->get('/api/shows/7')->assertJsonPath('data.id', 7);
    });

    it('boots a separate application for each client', function (): void {
        $first = TestClient::boot(httpAppPath());
        $second = TestClient::boot(httpAppPath());

        expect($first->application())->not->toBe($second->application());
    });

    it('throws a loud error when the base path does not exist', function (): void {
        TestClient::boot('/no/such/project');
    })->throws(RuntimeException::class, 'Base path does not exist');

    it('runs the global middleware stack', function (): void {
        TestClient::boot(httpAppPath())
            ->get('/api/shows/1')
            ->assertHeader('X-Global-Middleware', 'applied');
    });

    it('resets request-scoped state between two requests on one client', function (): void {
        $client = TestClient::boot(httpAppPath());

        $client->get('/counter')->assertSee('count=1');
        $client->get('/counter')->assertSee('count=1');
    });

    it('lets an exception thrown by a controller propagate', function (): void {
        TestClient::boot(httpAppPath())->get('/explode');
    })->throws(RuntimeException::class, 'Controller exploded');

    it('renders an HTTP exception the way production does', function (): void {
        TestClient::boot(httpAppPath())
            ->getJson('/api/shows/404')
            ->assertNotFound()
            ->assertJsonPath('message', 'Show not found');
    });

    it('returns the router 404 for an unknown route', function (): void {
        TestClient::boot(httpAppPath())->get('/no-such-route')->assertNotFound();
    });
});

describe('TestClient verbs', function (): void {
    it('sends each verb helper with its method', function (string $verb, string $method): void {
        TestClient::boot(httpAppPath())
            ->$verb('/echo')
            ->assertOk()
            ->assertJsonPath('method', $method)
            ->assertJsonPath('server.REQUEST_METHOD', $method);
    })->with([
        'get' => ['get', 'GET'],
        'post' => ['post', 'POST'],
        'put' => ['put', 'PUT'],
        'patch' => ['patch', 'PATCH'],
        'delete' => ['delete', 'DELETE'],
        'options' => ['options', 'OPTIONS'],
    ]);

    it('sends HEAD to the HEAD route and returns its headers without a body', function (): void {
        $response = TestClient::boot(httpAppPath())
            ->head('/echo')
            ->assertOk()
            ->assertHeader('X-Echo-Method', 'HEAD')
            ->assertHeader('Content-Type', 'application/json');

        expect($response->body())->toBe('');
    });

    it('sends form data for body verbs as Request::post() input, form-encoded', function (string $verb): void {
        TestClient::boot(httpAppPath())
            ->$verb('/echo', ['name' => 'Ada', 'tags' => ['a', 'b']])
            ->assertJsonPath('post', ['name' => 'Ada', 'tags' => ['a', 'b']])
            ->assertJsonPath('server.CONTENT_TYPE', 'application/x-www-form-urlencoded')
            ->assertJsonPath('body', 'name=Ada&tags%5B0%5D=a&tags%5B1%5D=b')
            ->assertJsonPath('query', []);
    })->with(['post', 'put', 'patch', 'delete', 'options']);

    it('sends data for GET as the query string', function (): void {
        TestClient::boot(httpAppPath())
            ->get('/echo', ['page' => '2'])
            ->assertJsonPath('query', ['page' => '2'])
            ->assertJsonPath('post', [])
            ->assertJsonPath('server.REQUEST_URI', '/echo?page=2')
            ->assertJsonPath('server.QUERY_STRING', 'page=2');
    });

    it('sends data for HEAD as the query string', function (): void {
        TestClient::boot(httpAppPath())
            ->head('/echo', ['page' => '2'])
            ->assertHeader('X-Echo-Method', 'HEAD')
            ->assertHeader('X-Echo-Request-Uri', '/echo?page=2');
    });

    it('parses a query string in the URI and merges data into it', function (): void {
        TestClient::boot(httpAppPath())
            ->get('/echo?sort=asc&page=1', ['page' => '3'])
            ->assertJsonPath('path', '/echo')
            ->assertJsonPath('query', ['sort' => 'asc', 'page' => '3'])
            ->assertJsonPath('server.REQUEST_URI', '/echo?sort=asc&page=3')
            ->assertJsonPath('server.QUERY_STRING', 'sort=asc&page=3');
    });

    it('sends an empty query string when there is none', function (): void {
        TestClient::boot(httpAppPath())
            ->get('/echo')
            ->assertJsonPath('server.REQUEST_URI', '/echo')
            ->assertJsonPath('server.QUERY_STRING', '');
    });

    it('accepts a full URL and routes on its path', function (): void {
        TestClient::boot(httpAppPath())
            ->get('https://example.test/echo?x=1')
            ->assertJsonPath('path', '/echo')
            ->assertJsonPath('query', ['x' => '1'])
            ->assertJsonPath('server.HTTP_HOST', 'example.test');
    });

    it('sends a raw body with any method via call()', function (): void {
        TestClient::boot(httpAppPath())
            ->call('PUT', '/echo', body: '<xml/>', headers: ['Content-Type' => 'application/xml'])
            ->assertJsonPath('method', 'PUT')
            ->assertJsonPath('body', '<xml/>')
            ->assertJsonPath('server.CONTENT_TYPE', 'application/xml')
            ->assertJsonPath('server.CONTENT_LENGTH', '6');
    });
});

describe('TestClient router method handling', function (): void {
    it('serves HEAD from the GET route with the body stripped, like production', function (): void {
        $response = TestClient::boot(httpAppPath())
            ->head('/counter')
            ->assertOk()
            ->assertHeader('X-Global-Middleware', 'applied');

        expect($response->body())->toBe('');
    });

    it('answers OPTIONS without an OPTIONS route with an automatic 204 and Allow', function (): void {
        TestClient::boot(httpAppPath())
            ->options('/counter')
            ->assertNoContent()
            ->assertHeader('Allow', 'GET, HEAD, OPTIONS')
            ->assertHeader('X-Global-Middleware', 'applied');
    });

    it('answers a method the path has no route for with 405 and Allow', function (): void {
        TestClient::boot(httpAppPath())
            ->post('/counter')
            ->assertStatus(405)
            ->assertHeader('Allow', 'GET, HEAD, OPTIONS')
            ->assertHeader('X-Global-Middleware', 'applied');
    });
});

it('refuses form data and a raw body in one call', function (): void {
    TestClient::boot(httpAppPath())->call('POST', '/echo', ['a' => 1], body: 'raw');
})->throws(TestClientException::class, 'Cannot send both form data and a raw body with POST /echo.');

describe('TestClient JSON helpers', function (): void {
    it(
        'sends the data as a JSON body with JSON Content-Type and Accept headers',
        function (string $verb, string $method): void {
            TestClient::boot(httpAppPath())
                ->$verb('/echo', ['type' => 'view', 'count' => 3])
                ->assertJsonPath('method', $method)
                ->assertJsonPath('json', ['type' => 'view', 'count' => 3])
                ->assertJsonPath('body', '{"type":"view","count":3}')
                ->assertJsonPath('post', [])
                ->assertJsonPath('server.CONTENT_TYPE', 'application/json')
                ->assertJsonPath('server.CONTENT_LENGTH', '25')
                ->assertJsonPath('server.HTTP_ACCEPT', 'application/json');
        },
    )->with([
        'postJson' => ['postJson', 'POST'],
        'putJson' => ['putJson', 'PUT'],
        'patchJson' => ['patchJson', 'PATCH'],
        'deleteJson' => ['deleteJson', 'DELETE'],
    ]);

    it('sends getJson data as the query string with a JSON Accept header and no body', function (): void {
        TestClient::boot(httpAppPath())
            ->getJson('/echo', ['page' => '2'])
            ->assertJsonPath('method', 'GET')
            ->assertJsonPath('query', ['page' => '2'])
            ->assertJsonPath('body', '')
            ->assertJsonPath('server.HTTP_ACCEPT', 'application/json');
    });

    it('lets a controller read the JSON payload like in production', function (): void {
        TestClient::boot(httpAppPath())
            ->postJson('/api/shows/42/events', ['type' => 'view'])
            ->assertStatus(202)
            ->assertJsonPath('data.show', 42)
            ->assertJsonPath('data.type', 'view');
    });

    it('lets per-call headers override the JSON defaults', function (): void {
        TestClient::boot(httpAppPath())
            ->postJson('/echo', ['a' => 1], ['Accept' => 'application/vnd.api+json'])
            ->assertJsonPath('server.HTTP_ACCEPT', 'application/vnd.api+json');
    });
});

describe('TestClient headers and server variables', function (): void {
    it('maps headers to HTTP_* server keys', function (): void {
        TestClient::boot(httpAppPath())
            ->get('/echo', headers: ['X-Request-Id' => 'abc-123', 'accept' => 'text/html'])
            ->assertJsonPath('server.HTTP_X_REQUEST_ID', 'abc-123')
            ->assertJsonPath('server.HTTP_ACCEPT', 'text/html');
    });

    it('maps Content-Type and Content-Length to CONTENT_TYPE and CONTENT_LENGTH', function (): void {
        $response = TestClient::boot(httpAppPath())
            ->call('POST', '/echo', body: 'abc', headers: ['Content-Type' => 'text/plain', 'Content-Length' => '3']);

        $response->assertJsonPath('server.CONTENT_TYPE', 'text/plain')
            ->assertJsonPath('server.CONTENT_LENGTH', '3');
    });

    it('keeps headers set with withHeaders for every later request', function (): void {
        $client = TestClient::boot(httpAppPath())->withHeaders(['X-Request-Id' => 'sticky']);

        $client->get('/echo')->assertJsonPath('server.HTTP_X_REQUEST_ID', 'sticky');
        $client->get('/echo')->assertJsonPath('server.HTTP_X_REQUEST_ID', 'sticky');
    });

    it('lets per-call headers override withHeaders for that request only', function (): void {
        $client = TestClient::boot(httpAppPath())->withHeader('X-Request-Id', 'sticky');

        $client->get('/echo', headers: ['X-Request-Id' => 'once'])->assertJsonPath('server.HTTP_X_REQUEST_ID', 'once');
        $client->get('/echo')->assertJsonPath('server.HTTP_X_REQUEST_ID', 'sticky');
    });

    it('defaults REMOTE_ADDR to 127.0.0.1 and the host to localhost', function (): void {
        TestClient::boot(httpAppPath())
            ->get('/echo')
            ->assertJsonPath('server.REMOTE_ADDR', '127.0.0.1')
            ->assertJsonPath('server.HTTP_HOST', 'localhost')
            ->assertJsonPath('server.SERVER_NAME', 'localhost');
    });

    it('overrides server variables with withServerVariables', function (): void {
        TestClient::boot(httpAppPath())
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/echo')
            ->assertJsonPath('server.REMOTE_ADDR', '203.0.113.9');
    });
});

describe('TestClient file uploads', function (): void {
    it('sends a file added with withFile as an UploadedFile', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'marko-upload-');
        file_put_contents($path, 'hello upload');

        try {
            TestClient::boot(httpAppPath())
                ->withFile('avatar', $path, 'me.txt')
                ->post('/echo', ['caption' => 'Me'])
                ->assertJsonPath('post', ['caption' => 'Me'])
                ->assertJsonPath('files.avatar.client_filename', 'me.txt')
                ->assertJsonPath('files.avatar.client_media_type', 'text/plain')
                ->assertJsonPath('files.avatar.size', 12)
                ->assertJsonPath('files.avatar.contents', 'hello upload')
                ->assertJsonPath('body', '');

            expect(file_get_contents($path))->toBe('hello upload');
        } finally {
            unlink($path);
        }
    });

    it('sends a file with a multipart Content-Type', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'marko-upload-');
        file_put_contents($path, 'x');

        try {
            $contentType = TestClient::boot(httpAppPath())
                ->withFile('avatar', $path)
                ->post('/echo')
                ->json('server.CONTENT_TYPE');

            expect($contentType)->toStartWith('multipart/form-data; boundary=');
        } finally {
            unlink($path);
        }
    });

    it('sends files with the next request only', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'marko-upload-');
        file_put_contents($path, 'x');

        try {
            $client = TestClient::boot(httpAppPath())->withFile('avatar', $path);

            $client->post('/echo')->assertJsonCount(1, 'files');
            $client->post('/echo')->assertJsonCount(0, 'files');
        } finally {
            unlink($path);
        }
    });

    it('throws a loud error for a file that does not exist', function (): void {
        TestClient::boot(httpAppPath())->withFile('avatar', '/no/such/file.png');
    })->throws(TestClientException::class, 'Cannot upload [/no/such/file.png]');
});

it('builds a Response the router returned without changes', function (): void {
    $response = TestClient::boot(httpAppPath())->get('/api/shows/1');

    expect($response->response())->toBeInstanceOf(Response::class);
});
