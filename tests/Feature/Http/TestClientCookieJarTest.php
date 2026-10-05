<?php

declare(strict_types=1);

use Marko\Testing\Http\TestClient;

use function Marko\Testing\Tests\httpAppPath;
use function Marko\Testing\Tests\removeHttpAppSessions;

afterAll(function (): void {
    removeHttpAppSessions();
});

describe('TestClient cookie jar', function (): void {
    it('sends cookies set by a previous response', function (): void {
        $client = TestClient::boot(httpAppPath());

        $client->get('/locale/nl')->assertCookie('locale', 'nl');

        $client->get('/')->assertSee('Welkom');
        $client->get('/echo')->assertJsonPath('cookies.locale', 'nl');
        expect($client->cookies())->toHaveKey('locale', 'nl');
    });

    it('sends the jar as request cookies and as a Cookie header', function (): void {
        TestClient::boot(httpAppPath())
            ->withCookies(['locale' => 'nl nl', 'theme' => 'dark'])
            ->get('/echo')
            ->assertJsonPath('cookies', ['locale' => 'nl nl', 'theme' => 'dark'])
            ->assertJsonPath('server.HTTP_COOKIE', 'locale=nl%20nl; theme=dark');
    });

    it('removes a cookie the response expires', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/locale/nl');

        $client->get('/locale-forget');

        expect($client->cookies())->not->toHaveKey('locale');
        $client->get('/')->assertSee('Welcome');
    });

    it('sends a cookie added with withCookie', function (): void {
        TestClient::boot(httpAppPath())
            ->withCookie('locale', 'nl')
            ->get('/')
            ->assertSee('Welkom');
    });

    it('clears the jar with withoutCookies', function (): void {
        $client = TestClient::boot(httpAppPath())->withCookies(['locale' => 'nl', 'theme' => 'dark']);

        $client->withoutCookies();

        expect($client->cookies())->toBe([]);
        $client->get('/')->assertSee('Welcome');
    });

    it('keeps the session across requests: form login, then a protected page', function (): void {
        $client = TestClient::boot(httpAppPath());

        $client->post('/login', ['user_id' => '1'])
            ->assertRedirect('/dashboard')
            ->assertCookie('marko_session');

        $client->get('/dashboard')->assertOk()->assertSee('Dashboard for user 1');
    });

    it('starts a new session after withoutCookies', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->post('/login', ['user_id' => '1']);

        $client->withoutCookies()->get('/dashboard')->assertRedirect('/login');
    });

    it('does not share cookies between clients', function (): void {
        $first = TestClient::boot(httpAppPath());
        $second = TestClient::boot(httpAppPath());

        $first->post('/login', ['user_id' => '1']);

        $second->get('/dashboard')->assertRedirect('/login');
    });
});
