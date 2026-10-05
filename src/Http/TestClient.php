<?php

declare(strict_types=1);

namespace Marko\Testing\Http;

use finfo;
use JsonException;
use Marko\Authentication\AuthenticatableInterface;
use Marko\Authentication\AuthManager;
use Marko\Authentication\Config\AuthConfig;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Config\Exceptions\ConfigNotFoundException;
use Marko\Core\Application;
use Marko\Core\Exceptions\BindingConflictException;
use Marko\Core\Exceptions\BindingException;
use Marko\Core\Exceptions\CircularDependencyException;
use Marko\Core\Exceptions\CommandException;
use Marko\Core\Exceptions\DiscoveryCacheException;
use Marko\Core\Exceptions\EventException;
use Marko\Core\Exceptions\ModuleException;
use Marko\Core\Exceptions\PluginException;
use Marko\Core\Exceptions\PreferenceConflictException;
use Marko\Core\RequestStateResetter;
use Marko\Routing\Exceptions\RouteConflictException;
use Marko\Routing\Exceptions\RouteException;
use Marko\Routing\Http\Cookie;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\Http\UploadedFile;
use Marko\Testing\Exceptions\TestClientException;
use Marko\Testing\Fake\FakeGuard;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use ReflectionException;
use RuntimeException;

/**
 * Sends requests through a booted Marko application in process, the way a
 * browser or API client would: the real router, global and route middleware,
 * and controllers all run. No web server, no superglobals.
 *
 * The application boots once per client, and one client serves many requests.
 * Before each request, every resolved ResettableInterface service is reset
 * (the same {@see RequestStateResetter} the RoadRunner worker uses), so
 * request-scoped state such as the session never leaks between requests.
 *
 * The client is stateful, like a browser: headers set with withHeaders(),
 * server variables, the cookie jar and actingAs() apply to every later request
 * on the same client. withFile() applies to the next request only.
 *
 * An exception thrown by a controller or middleware propagates out of the call,
 * so the test shows the real stack trace. HTTP exceptions
 * (HttpExceptionInterface) are already rendered into a response by the router
 * pipeline, exactly as in production.
 */
class TestClient
{
    private const string MULTIPART_BOUNDARY = 'MarkoTestClientBoundary';

    private readonly RequestStateResetter $resetter;

    /** @var array<string, string> Server keys (HTTP_*, CONTENT_TYPE, ...) => value */
    private array $headers = [];

    /** @var array<string, string> */
    private array $serverVariables = [];

    /** @var array<string, string> Cookie name => value */
    private array $cookies = [];

    /** @var array<string, UploadedFile> */
    private array $files = [];

    public function __construct(
        private readonly Application $application,
    ) {
        $this->resetter = new RequestStateResetter($application->container);
    }

    /**
     * Boot the application at $basePath (the project root holding vendor/, app/ and config/).
     *
     * @throws ModuleException|CircularDependencyException|BindingConflictException|BindingException|PluginException|PreferenceConflictException|EventException|ContainerExceptionInterface|RouteException|RouteConflictException|CommandException|ReflectionException|RuntimeException|DiscoveryCacheException
     */
    public static function boot(
        string $basePath,
    ): self {
        return new self(Application::boot($basePath));
    }

    /**
     * Send requests through an application that is already booted.
     */
    public static function forApplication(
        Application $application,
    ): self {
        return new self($application);
    }

    public function application(): Application
    {
        return $this->application;
    }

    /**
     * Send these headers with every later request. Per-call headers win for that call.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(
        array $headers,
    ): static {
        foreach ($headers as $name => $value) {
            $this->headers[self::headerServerKey($name)] = $value;
        }

        return $this;
    }

    public function withHeader(
        string $name,
        string $value,
    ): static {
        return $this->withHeaders([$name => $value]);
    }

    /**
     * Set $_SERVER-style variables (e.g. REMOTE_ADDR, HTTPS) for every later request.
     * They are applied last, so they override what the client computes.
     *
     * @param array<string, string> $variables
     */
    public function withServerVariables(
        array $variables,
    ): static {
        $this->serverVariables = [...$this->serverVariables, ...$variables];

        return $this;
    }

    /**
     * Put a cookie in the jar; it is sent with every later request.
     */
    public function withCookie(
        string $name,
        string $value,
    ): static {
        $this->cookies[$name] = $value;

        return $this;
    }

    /**
     * @param array<string, string> $cookies
     */
    public function withCookies(
        array $cookies,
    ): static {
        foreach ($cookies as $name => $value) {
            $this->withCookie($name, $value);
        }

        return $this;
    }

    /**
     * Empty the cookie jar, like a fresh browser.
     */
    public function withoutCookies(): static
    {
        $this->cookies = [];

        return $this;
    }

    /**
     * The cookie jar: cookies set by earlier responses or withCookie(), sent with the next request.
     * Cookies are keyed by name only; domain and path are not matched.
     *
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Upload a file with the next request, as the form field $field. The file is copied
     * first, so the controller can move the upload without touching the original.
     *
     * @throws TestClientException
     */
    public function withFile(
        string $field,
        string $path,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): static {
        if (!is_file($path) || !is_readable($path)) {
            throw TestClientException::unreadableUpload($path);
        }

        $copy = (string) tempnam(sys_get_temp_dir(), 'marko-test-upload-');
        copy($path, $copy);

        $this->files[$field] = new UploadedFile(
            tempPath: $copy,
            clientFilename: $clientFilename ?? basename($path),
            clientMediaType: $clientMediaType ?? (string) new finfo(FILEINFO_MIME_TYPE)->file($copy),
            size: (int) filesize($copy),
        );

        return $this;
    }

    /**
     * Authenticate as $user for every later request, without a login request and without
     * writing to the session: a FakeGuard holding the user replaces the guard named $guard
     * (the default guard when null) in the application's AuthManager. For the default
     * guard, the container's GuardInterface instance is replaced too.
     *
     * Needs the marko/authentication module loaded by the application; without it, resolving
     * AuthManager fails with the container's binding error.
     *
     * @throws ContainerExceptionInterface|ConfigNotFoundException
     */
    public function actingAs(
        AuthenticatableInterface $user,
        ?string $guard = null,
    ): static {
        $container = $this->application->container;
        $authManager = $container->get(AuthManager::class);
        $defaultGuard = $container->get(AuthConfig::class)->defaultGuard();
        $name = $guard ?? $defaultGuard;

        $fakeGuard = new FakeGuard(name: $name);
        $fakeGuard->setUser($user);
        $authManager->useGuard($name, $fakeGuard);

        if ($name === $defaultGuard) {
            $container->instance(GuardInterface::class, $fakeGuard);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function get(
        string $uri,
        array $query = [],
        array $headers = [],
    ): TestResponse {
        return $this->call('GET', $uri, $query, $headers);
    }

    /**
     * @param array<string, mixed> $data form fields, sent form-encoded (multipart with withFile())
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function post(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->call('POST', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function put(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->call('PUT', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function patch(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->call('PATCH', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function delete(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->call('DELETE', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function options(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->call('OPTIONS', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function head(
        string $uri,
        array $query = [],
        array $headers = [],
    ): TestResponse {
        return $this->call('HEAD', $uri, $query, $headers);
    }

    /**
     * A GET with `Accept: application/json`. GET requests carry no body, so $query is sent as the query string.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function getJson(
        string $uri,
        array $query = [],
        array $headers = [],
    ): TestResponse {
        return $this->call('GET', $uri, $query, ['Accept' => 'application/json', ...$headers]);
    }

    /**
     * @param array<mixed> $data sent as the JSON body
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function postJson(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->json('POST', $uri, $data, $headers);
    }

    /**
     * @param array<mixed> $data sent as the JSON body
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function putJson(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->json('PUT', $uri, $data, $headers);
    }

    /**
     * @param array<mixed> $data sent as the JSON body
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function patchJson(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->json('PATCH', $uri, $data, $headers);
    }

    /**
     * @param array<mixed> $data sent as the JSON body
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function deleteJson(
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->json('DELETE', $uri, $data, $headers);
    }

    /**
     * Send $data as a JSON body with JSON Content-Type and Accept headers.
     *
     * @param array<mixed> $data
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function json(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
    ): TestResponse {
        return $this->call(
            $method,
            $uri,
            headers: [...self::jsonHeaders(), ...$headers],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Send any request. For GET and HEAD, $data is the query string. For other methods,
     * $data is form data (Request::post()), unless a raw $body is given instead.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @throws ContainerExceptionInterface|ReflectionException|JsonException|TestClientException
     */
    public function call(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        ?string $body = null,
    ): TestResponse {
        $method = strtoupper($method);
        $request = $this->buildRequest($method, $uri, $data, $headers, $body);
        $files = $this->files;
        $this->files = [];

        try {
            // Reset before, not after, like the RoadRunner worker: state left by a
            // request that threw must not reach the next one.
            $this->resetter->reset();
            $response = $this->application->router->handle($request);
        } finally {
            foreach ($files as $file) {
                if (is_file($file->tempPath())) {
                    unlink($file->tempPath());
                }
            }
        }

        $this->storeCookies($response);

        return new TestResponse($response);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @throws TestClientException
     */
    private function buildRequest(
        string $method,
        string $uri,
        array $data,
        array $headers,
        ?string $body,
    ): Request {
        $parts = parse_url($uri);
        $parts = $parts === false ? [] : $parts;
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        parse_str($parts['query'] ?? '', $query);
        $host = $parts['host'] ?? 'localhost';
        $secure = ($parts['scheme'] ?? 'http') === 'https';

        $post = [];
        $files = [];
        $contentType = null;
        $bodyless = $method === 'GET' || $method === 'HEAD';

        if ($bodyless) {
            $query = [...$query, ...$data];
        } elseif ($body !== null && $data !== []) {
            throw TestClientException::bodyAndData($method, $uri);
        } elseif ($body === null) {
            $post = $data;
            $files = $this->files;

            if ($files !== []) {
                $contentType = 'multipart/form-data; boundary=' . self::MULTIPART_BOUNDARY;
            } elseif ($data !== []) {
                $contentType = 'application/x-www-form-urlencoded';
                $body = http_build_query($data);
            }
        }

        $body ??= '';
        $queryString = http_build_query($query);

        $server = [
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => $host,
            'SERVER_PORT' => (string) ($parts['port'] ?? ($secure ? 443 : 80)),
            'HTTP_HOST' => isset($parts['port']) ? "$host:{$parts['port']}" : $host,
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_TIME' => time(),
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $queryString === '' ? $path : "$path?$queryString",
            'QUERY_STRING' => $queryString,
        ];

        if ($secure) {
            $server['HTTPS'] = 'on';
        }

        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        if ($body !== '') {
            $server['CONTENT_LENGTH'] = (string) strlen($body);
        }

        if ($this->cookies !== []) {
            $server['HTTP_COOKIE'] = implode('; ', array_map(
                static fn (string $name, string $value): string => $name . '=' . rawurlencode($value),
                array_keys($this->cookies),
                $this->cookies,
            ));
        }

        foreach ($headers as $name => $value) {
            $server[self::headerServerKey($name)] = $value;
        }

        $server = [...$server, ...$this->headersNotOverridden($headers), ...$this->serverVariables];

        return new Request(
            server: $server,
            query: $query,
            post: $post,
            body: $body,
            cookies: $this->cookies,
            files: $files,
        );
    }

    /**
     * The client-wide headers that the per-call $headers do not replace.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function headersNotOverridden(
        array $headers,
    ): array {
        $overridden = array_map(self::headerServerKey(...), array_keys($headers));

        return array_diff_key($this->headers, array_flip($overridden));
    }

    /**
     * Keep the cookies the response sets, and drop the ones it expires, like a browser.
     *
     * @throws ContainerExceptionInterface
     */
    private function storeCookies(
        Response $response,
    ): void {
        $cookies = $response->cookies();

        if ($cookies === []) {
            return;
        }

        $now = $this->now();

        foreach ($cookies as $cookie) {
            if (self::isExpired($cookie, $now)) {
                unset($this->cookies[$cookie->name()]);
            } else {
                $this->cookies[$cookie->name()] = $cookie->value();
            }
        }
    }

    /**
     * The application's clock when one is bound, so cookie expiry agrees with code that
     * expires cookies relative to that clock (e.g. SessionMiddleware); the system time otherwise.
     *
     * @throws ContainerExceptionInterface
     */
    private function now(): int
    {
        $container = $this->application->container;

        if ($container->has(ClockInterface::class)) {
            return $container->get(ClockInterface::class)->now()->getTimestamp();
        }

        return time();
    }

    private static function isExpired(
        Cookie $cookie,
        int $now,
    ): bool {
        $expires = $cookie->expires();

        return $expires !== null && $expires !== 0 && $expires <= $now;
    }

    private static function headerServerKey(
        string $name,
    ): string {
        $key = strtoupper(str_replace('-', '_', $name));

        return $key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' ? $key : 'HTTP_' . $key;
    }

    /**
     * @return array<string, string>
     */
    private static function jsonHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
