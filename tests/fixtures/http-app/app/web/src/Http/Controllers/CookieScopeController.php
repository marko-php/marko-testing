<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Controllers;

use JsonException;
use Marko\Routing\Attributes\Get;
use Marko\Routing\Attributes\WithoutMiddleware;
use Marko\Routing\Exceptions\CookieException;
use Marko\Routing\Http\Cookie;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Session\Middleware\SessionMiddleware;

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
    /**
     * Query: name, value, and optionally path, domain, secure=1, expired=1.
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

        return new Response('cookie set')
            ->withCookie(new Cookie(
                name: (string) $request->query('name'),
                value: (string) $request->query('value', ''),
                expires: $request->query('expired') === '1' ? time() - 3600 : null,
                path: is_string($path) ? $path : null,
                domain: is_string($domain) ? $domain : null,
                secure: $request->query('secure') === '1',
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
}
