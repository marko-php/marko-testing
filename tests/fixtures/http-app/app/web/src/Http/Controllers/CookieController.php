<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Controllers;

use Marko\Routing\Attributes\Get;
use Marko\Routing\Exceptions\CookieException;
use Marko\Routing\Http\Cookie;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;

/**
 * Sets, expires and reads a cookie.
 *
 * @noinspection PhpUnused Route actions are invoked via reflection by the Router.
 */
class CookieController
{
    #[Get('/')]
    public function home(
        Request $request,
    ): Response {
        return Response::html($request->cookie('locale') === 'nl' ? '<h1>Welkom</h1>' : '<h1>Welcome</h1>');
    }

    /**
     * @throws CookieException
     */
    #[Get('/locale/{locale}')]
    public function setLocale(
        string $locale,
    ): Response {
        return new Response('locale set')
            ->withCookie(new Cookie(name: 'locale', value: $locale, path: '/'));
    }

    /**
     * @throws CookieException
     */
    #[Get('/locale-forget')]
    public function forgetLocale(): Response
    {
        return new Response('locale forgotten')
            ->withCookie(new Cookie(name: 'locale', value: '', expires: time() - 3600, path: '/'));
    }
}
