<?php

declare(strict_types=1);

use Marko\Authentication\AuthManager;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Testing\Fake\FakeAuthenticatable;
use Marko\Testing\Fake\FakeGuard;
use Marko\Testing\Http\TestClient;

use function Marko\Testing\Tests\httpAppPath;
use function Marko\Testing\Tests\removeHttpAppSessions;

afterAll(function (): void {
    removeHttpAppSessions();
});

/**
 * @return list<string> every session file the fixture app wrote for this process
 */
function httpAppSessionFiles(): array
{
    return glob(sys_get_temp_dir() . '/marko-testing-http-app/' . getmypid() . '/sessions/*') ?: [];
}

describe('TestClient actingAs', function (): void {
    it('reaches a protected route as the given user', function (): void {
        TestClient::boot(httpAppPath())
            ->actingAs(new FakeAuthenticatable(id: 7))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Dashboard for user 7');
    });

    it('redirects from the protected route without a user', function (): void {
        TestClient::boot(httpAppPath())
            ->get('/dashboard')
            ->assertRedirect('/login');
    });

    it('stays authenticated for every later request on the client', function (): void {
        $client = TestClient::boot(httpAppPath())->actingAs(new FakeAuthenticatable(id: 7));

        $client->get('/dashboard')->assertOk();
        $client->get('/dashboard')->assertOk();
    });

    it('applies when called after the client already served a request', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/dashboard')->assertRedirect('/login');

        $client->actingAs(new FakeAuthenticatable(id: 7))->get('/dashboard')->assertOk();
    });

    it('does not write the user to the session', function (): void {
        $before = httpAppSessionFiles();

        TestClient::boot(httpAppPath())
            ->actingAs(new FakeAuthenticatable(id: 7))
            ->get('/dashboard')
            ->assertOk();

        $written = array_diff(httpAppSessionFiles(), $before);
        $authKeys = array_filter(
            $written,
            static fn (string $file): bool => str_contains((string) file_get_contents($file), 'auth_'),
        );

        expect($authKeys)->toBe([]);
    });

    it('puts a FakeGuard holding the user in place for the default guard', function (): void {
        $user = new FakeAuthenticatable(id: 7);
        $client = TestClient::boot(httpAppPath())->actingAs($user);
        $container = $client->application()->container;

        $guard = $container->get(AuthManager::class)->guard();

        expect($guard)->toBeInstanceOf(FakeGuard::class)
            ->and($guard->user())->toBe($user)
            ->and($guard->getName())->toBe('web')
            ->and($container->get(GuardInterface::class))->toBe($guard);
    });

    it('acts as the user on a named guard only', function (): void {
        $client = TestClient::boot(httpAppPath())->actingAs(new FakeAuthenticatable(id: 9), 'api');

        $client->get('/api/me')->assertOk()->assertExactJson(['id' => 9, 'guard' => 'api']);
        $client->get('/dashboard')->assertRedirect('/login');
    });

    it('does not affect a second client', function (): void {
        TestClient::boot(httpAppPath())->actingAs(new FakeAuthenticatable(id: 7));

        TestClient::boot(httpAppPath())->get('/dashboard')->assertRedirect('/login');
    });
});
