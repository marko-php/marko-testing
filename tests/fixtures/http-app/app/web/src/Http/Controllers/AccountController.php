<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\HttpApp\Http\Controllers;

use JsonException;
use Marko\Authentication\AuthManager;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Authentication\Exceptions\AuthException;
use Marko\Authentication\Middleware\AuthMiddleware;
use Marko\Routing\Attributes\Get;
use Marko\Routing\Attributes\Middleware;
use Marko\Routing\Attributes\Post;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;

/**
 * A form login backed by the real session guard, and a page behind AuthMiddleware.
 *
 * @noinspection PhpUnused Route actions are invoked via reflection by the Router.
 */
class AccountController
{
    public function __construct(
        private readonly GuardInterface $guard,
        private readonly AuthManager $auth,
    ) {}

    #[Post('/login')]
    public function login(
        Request $request,
    ): Response {
        $user = $this->guard->loginById((string) $request->post('user_id', ''));

        return Response::redirect($user === null ? '/login' : '/dashboard');
    }

    #[Get('/dashboard')]
    #[Middleware(AuthMiddleware::class)]
    public function dashboard(): Response
    {
        return new Response('Dashboard for user ' . $this->guard->id());
    }

    /**
     * @throws AuthException|JsonException
     */
    #[Get('/api/me')]
    public function apiMe(): Response
    {
        $guard = $this->auth->guard('api');

        if (!$guard->check()) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        return Response::json(['id' => $guard->id(), 'guard' => $guard->getName()]);
    }

    #[Get('/old-dashboard')]
    public function oldDashboard(): Response
    {
        return Response::redirect('/dashboard', 301);
    }
}
