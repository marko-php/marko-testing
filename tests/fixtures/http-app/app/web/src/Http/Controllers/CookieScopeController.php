<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Controllers;

use JsonException;
use Marko\Routing\Attributes\Get;
use Marko\Routing\Attributes\Post;
use Marko\Routing\Attributes\WithoutMiddleware;
use Marko\Routing\Exceptions\CookieException;
use Marko\Routing\Http\Cookie;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Session\Middleware\SessionMiddleware;
use Psr\Clock\ClockInterface;

/**
 * Sets a cookie described by the query string and echoes the cookies a request carries,
 * so the tests can exercise the TestClient cookie jar's path, domain and Secure matching.
 * The session middleware is skipped, so its session cookie does not crowd the jar.
 *
 * @noinspection PhpUnused Route actions are invoked via reflection by the Router.
 */
#[WithoutMiddleware(SessionMiddleware::class)]
class CookieScopeController
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Query: name, value, and optionally path, domain, secure=1, expired=1, expires (a Unix timestamp),
     * expires_in (seconds from the application clock), max_age (seconds) and same_site.
     *
     * @throws CookieException
     */
    #[Get('/jar/set')]
    public function set(
        Request $request,
    ): Response {
        return $this->setCookie($request);
    }

    /**
     * The same as set(), one directory deeper, to exercise the default cookie path.
     *
     * @throws CookieException
     */
    #[Get('/jar/deep/set')]
    public function setDeep(
        Request $request,
    ): Response {
        return $this->setCookie($request);
    }

    /**
     * @throws CookieException
     */
    private function setCookie(
        Request $request,
    ): Response {
        $path = $request->query('path');
        $domain = $request->query('domain');
        $expiresIn = $request->query('expires_in');
        $maxAge = $request->query('max_age');
        $sameSite = $request->query('same_site');
        $now = $this->clock->now()->getTimestamp();
        $expires = is_string($expiresIn) ? $now + (int) $expiresIn : null;
        $absoluteExpires = $request->query('expires');
        $expires = is_string($absoluteExpires) ? (int) $absoluteExpires : $expires;

        return new Response('cookie set')
            ->withCookie(new Cookie(
                name: (string) $request->query('name'),
                value: (string) $request->query('value', ''),
                expires: $request->query('expired') === '1' ? $now - 3600 : $expires,
                path: is_string($path) ? $path : null,
                domain: is_string($domain) ? $domain : null,
                secure: $request->query('secure') === '1',
                sameSite: is_string($sameSite) ? $sameSite : null,
                maxAge: is_string($maxAge) ? (int) $maxAge : null,
            ));
    }

    /**
     * @throws JsonException
     */
    #[Get('/jar/{rest*}')]
    public function echo(
        Request $request,
    ): Response {
        return Response::json([
            'cookies' => $request->cookie(),
            'header' => $request->server('HTTP_COOKIE'),
        ]);
    }

    /**
     * The same as echo(), for a POST, to exercise SameSite=Lax on cross-site requests.
     *
     * @throws JsonException
     */
    #[Post('/jar/{rest*}')]
    public function echoPost(
        Request $request,
    ): Response {
        return $this->echo($request);
    }
}
