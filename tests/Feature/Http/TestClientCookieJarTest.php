<?php

declare(strict_types=1);

use Marko\Testing\Http\JarCookie;
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

describe('TestClient cookie jar scoping', function (): void {
    it('sends a Path=/admin cookie to /admin/x but not to /api', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/set', ['name' => 'admin_token', 'value' => 'a', 'path' => '/jar/admin']);

        $client->get('/jar/admin/x')->assertJsonPath('cookies', ['admin_token' => 'a']);
        $client->get('/jar/admin')->assertJsonPath('cookies', ['admin_token' => 'a']);
        $client->get('/jar/api')->assertJsonPath('cookies', []);
        $client->get('/jar/administrator')->assertJsonPath('cookies', []);
    });

    it('keeps same-name cookies with different paths side by side', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/set', ['name' => 'token', 'value' => 'admin', 'path' => '/jar/admin']);
        $client->get('/jar/set', ['name' => 'token', 'value' => 'api', 'path' => '/jar/api']);

        $client->get('/jar/admin/x')->assertJsonPath('cookies', ['token' => 'admin']);
        $client->get('/jar/api/x')->assertJsonPath('cookies', ['token' => 'api']);
        expect($client->cookieJar())->toHaveCount(2);
    });

    it(
        'sends the more specific path first when two same-name cookies match, and exposes the first as the request cookie',
        function (): void {
            $client = TestClient::boot(httpAppPath());
            $client->get('/jar/set', ['name' => 'token', 'value' => 'root', 'path' => '/']);
            $client->get('/jar/set', ['name' => 'token', 'value' => 'admin', 'path' => '/jar/admin']);

            $client->get('/jar/admin/x')
                ->assertJsonPath('header', 'token=admin; token=root')
                ->assertJsonPath('cookies', ['token' => 'admin']);
        },
    );

    it('does not send a Secure cookie over http but sends it over https', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get(
            'https://localhost/jar/set',
            ['name' => 'secret', 'value' => 's', 'path' => '/', 'secure' => '1'],
        );

        $client->get('http://localhost/jar/x')->assertJsonPath('cookies', []);
        $client->get('https://localhost/jar/x')->assertJsonPath('cookies', ['secret' => 's']);
    });

    it(
        'sends relative-path requests over https, so a Secure cookie survives a relative round trip',
        function (): void {
            $client = TestClient::boot(httpAppPath());
            $client->get('/jar/set', ['name' => 'secret', 'value' => 's', 'path' => '/', 'secure' => '1']);

            $client->get('/jar/x')->assertJsonPath('cookies', ['secret' => 's']);
        },
    );

    it('treats the request as secure when HTTPS is set via withServerVariables', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get(
            'https://localhost/jar/set',
            ['name' => 'secret', 'value' => 's', 'path' => '/', 'secure' => '1'],
        );

        $client->withServerVariables(['HTTPS' => 'on'])
            ->get('http://localhost/jar/x')
            ->assertJsonPath('cookies', ['secret' => 's']);
    });

    it('treats a relative-path request as plain http when HTTPS is off via withServerVariables', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/set', ['name' => 'secret', 'value' => 's', 'path' => '/', 'secure' => '1']);

        $client->withServerVariables(['HTTPS' => 'off'])
            ->get('/jar/x')
            ->assertJsonPath('cookies', []);
    });

    it('removes an expired cookie only for its own name, domain and path', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/set', ['name' => 'token', 'value' => 'a', 'path' => '/jar/a']);
        $client->get('/jar/set', ['name' => 'token', 'value' => 'b', 'path' => '/jar/b']);

        $client->get('/jar/set', ['name' => 'token', 'path' => '/jar/a', 'expired' => '1']);

        $client->get('/jar/a')->assertJsonPath('cookies', []);
        $client->get('/jar/b')->assertJsonPath('cookies', ['token' => 'b']);
        expect($client->cookieJar())->toHaveCount(1)
            ->and($client->cookieJar()[0]->path)->toBe('/jar/b');
    });

    it('scopes a cookie without Path to the default path of the request', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/deep/set', ['name' => 'deep', 'value' => 'd']);

        $client->get('/jar/deep/x')->assertJsonPath('cookies', ['deep' => 'd']);
        $client->get('/jar/other')->assertJsonPath('cookies', []);
        expect($client->cookieJar()[0]->path)->toBe('/jar/deep');
    });

    it('sends a host-only cookie only to the host that set it', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('http://example.test/jar/set', ['name' => 'host', 'value' => 'h', 'path' => '/']);

        $client->get('http://example.test/jar/x')->assertJsonPath('cookies', ['host' => 'h']);
        $client->get('http://shop.example.test/jar/x')->assertJsonPath('cookies', []);
        $client->get('/jar/x')->assertJsonPath('cookies', []);
    });

    it('sends a Domain cookie to subdomains and ignores one for a foreign domain', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('http://example.test/jar/set', [
            'name' => 'shared',
            'value' => 's',
            'path' => '/',
            'domain' => '.Example.test',
        ]);

        $client->get('http://shop.example.test/jar/x')->assertJsonPath('cookies', ['shared' => 's']);
        $client->get('http://example.test/jar/x')->assertJsonPath('cookies', ['shared' => 's']);
        $client->get('http://badexample.test/jar/x')->assertJsonPath('cookies', []);
    });

    it('rejects a Set-Cookie whose Domain does not domain-match the request host', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get(
            'http://example.test/jar/set',
            ['name' => 'evil', 'value' => 'e', 'path' => '/', 'domain' => 'other.test'],
        );

        expect($client->cookieJar())->toBe([]);
        $client->get('http://other.test/jar/x')->assertJsonPath('cookies', []);
    });

    it('returns structured entries from cookieJar', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('https://example.test/jar/set', [
            'name' => 'token',
            'value' => 't',
            'path' => '/jar/admin',
            'domain' => 'example.test',
            'secure' => '1',
        ]);
        $client->get('http://example.test/jar/set', ['name' => 'host', 'value' => 'h', 'path' => '/']);

        [$domainCookie, $hostCookie] = $client->cookieJar();

        expect($domainCookie)->toBeInstanceOf(JarCookie::class)
            ->and($domainCookie->name)->toBe('token')
            ->and($domainCookie->value)->toBe('t')
            ->and($domainCookie->domain)->toBe('example.test')
            ->and($domainCookie->path)->toBe('/jar/admin')
            ->and($domainCookie->secure)->toBeTrue()
            ->and($domainCookie->hostOnly)->toBeFalse()
            ->and($hostCookie->domain)->toBe('example.test')
            ->and($hostCookie->hostOnly)->toBeTrue()
            ->and($hostCookie->secure)->toBeFalse();
    });

    it('returns only cookies on / from cookies()', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/set', ['name' => 'root', 'value' => 'r', 'path' => '/']);
        $client->get('/jar/set', ['name' => 'admin', 'value' => 'a', 'path' => '/jar/admin']);

        expect($client->cookies())->toBe(['root' => 'r']);
    });

    it('scopes a cookie added with withCookie to a path', function (): void {
        $client = TestClient::boot(httpAppPath())->withCookie('token', 't', path: '/jar/admin');

        $client->get('/jar/admin/x')->assertJsonPath('cookies', ['token' => 't']);
        $client->get('/jar/api')->assertJsonPath('cookies', []);
    });

    it('sends a Secure cookie added with withCookie over https only', function (): void {
        $client = TestClient::boot(httpAppPath())->withCookie('secret', 's', secure: true);

        $client->get('http://localhost/jar/x')->assertJsonPath('cookies', []);
        $client->get('/jar/x')->assertJsonPath('cookies', ['secret' => 's']);
    });

    it('sends a cookie added with withCookie and a domain only to that domain', function (): void {
        $client = TestClient::boot(httpAppPath())->withCookie('shared', 's', domain: 'example.test');

        $client->get('http://shop.example.test/jar/x')->assertJsonPath('cookies', ['shared' => 's']);
        $client->get('/jar/x')->assertJsonPath('cookies', []);
    });

    it('lets a response expire a cookie added with withCookie', function (): void {
        $client = TestClient::boot(httpAppPath())->withCookie('locale', 'nl');

        $client->get('/jar/set', ['name' => 'locale', 'path' => '/', 'expired' => '1']);

        expect($client->cookieJar())->toBe([]);
    });

    it('lets a response replace a cookie added with withCookie', function (): void {
        $client = TestClient::boot(httpAppPath())->withCookie('locale', 'en');

        $client->get('/jar/set', ['name' => 'locale', 'value' => 'nl', 'path' => '/']);

        expect($client->cookieJar())->toHaveCount(1)
            ->and($client->cookies())->toBe(['locale' => 'nl']);
    });
});
