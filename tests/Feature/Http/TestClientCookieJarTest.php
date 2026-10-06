<?php

declare(strict_types=1);

use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Http\JarCookie;
use Marko\Testing\Http\TestClient;

use function Marko\Testing\Tests\httpAppPath;
use function Marko\Testing\Tests\removeHttpAppSessions;

use Psr\Clock\ClockInterface;

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

/**
 * A client whose application reads time from a FakeClock, so a test can move past a cookie's expiry.
 */
function clientWithFakeClock(
    FakeClock $clock,
): TestClient {
    $client = TestClient::boot(httpAppPath());
    $client->application()->container->instance(ClockInterface::class, $clock);

    return $client;
}

describe('TestClient cookie jar expiry', function (): void {
    it('stops sending a cookie once its Expires passes on the bound clock', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $client = clientWithFakeClock($clock);
        $client->get('/jar/set', ['name' => 'token', 'value' => 't', 'path' => '/', 'expires_in' => '1800']);

        $client->get('/jar/x')->assertJsonPath('cookies', ['token' => 't']);
        $clock->travel('+1 hour');

        $client->get('/jar/x')->assertJsonPath('cookies', []);
    });

    it('stops sending a cookie once its Max-Age passes on the bound clock', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $client = clientWithFakeClock($clock);
        $client->get('/jar/set', ['name' => 'token', 'value' => 't', 'path' => '/', 'max_age' => '1800']);

        $clock->travel('+1799 seconds');
        $client->get('/jar/x')->assertJsonPath('cookies', ['token' => 't']);
        $clock->travel('+1 second');

        $client->get('/jar/x')->assertJsonPath('cookies', []);
    });

    it('lets Max-Age win over Expires', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $client = clientWithFakeClock($clock);
        $client->get('/jar/set', [
            'name' => 'short',
            'value' => 's',
            'path' => '/',
            'expires_in' => '86400',
            'max_age' => '60',
        ]);
        $client->get('/jar/set', [
            'name' => 'long',
            'value' => 'l',
            'path' => '/',
            'expires_in' => '60',
            'max_age' => '86400',
        ]);

        $clock->travel('+1 hour');

        $client->get('/jar/x')->assertJsonPath('cookies', ['long' => 'l']);
    });

    it('removes a cookie the response sets with Max-Age=0', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/set', ['name' => 'token', 'value' => 't', 'path' => '/']);

        $client->get('/jar/set', ['name' => 'token', 'path' => '/', 'max_age' => '0', 'expires_in' => '3600']);

        expect($client->cookieJar())->toBe([]);
    });

    it('exposes the expiry in cookieJar, null for a session cookie', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $client = clientWithFakeClock($clock);
        $client->get('/jar/set', ['name' => 'remember', 'value' => 'r', 'path' => '/', 'max_age' => '600']);
        $client->get('/jar/set', ['name' => 'session', 'value' => 's', 'path' => '/']);

        [$remember, $session] = $client->cookieJar();

        expect($remember->expiresAt)->toBe($clock->now()->getTimestamp() + 600)
            ->and($session->expiresAt)->toBeNull();
    });

    it('evicts expired cookies from cookies and cookieJar', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $client = clientWithFakeClock($clock);
        $client->get('/jar/set', ['name' => 'token', 'value' => 't', 'path' => '/', 'max_age' => '60']);
        $client->get('/jar/set', ['name' => 'locale', 'value' => 'nl', 'path' => '/']);

        $clock->travel('+2 minutes');

        expect($client->cookies())->toBe(['locale' => 'nl'])
            ->and($client->cookieJar())->toHaveCount(1);
    });

    it('keeps a cookie whose Expires is past when a positive Max-Age is set', function (): void {
        $client = clientWithFakeClock(new FakeClock('2026-01-01 12:00:00 UTC'));

        $client->get(
            '/jar/set',
            ['name' => 'token', 'value' => 't', 'path' => '/', 'expired' => '1', 'max_age' => '60'],
        );

        $client->get('/jar/x')->assertJsonPath('cookies', ['token' => 't']);
    });

    it('removes a cookie the response sets with a negative Max-Age', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/set', ['name' => 'token', 'value' => 't', 'path' => '/']);

        $client->get('/jar/set', ['name' => 'token', 'path' => '/', 'max_age' => '-1']);

        expect($client->cookieJar())->toBe([]);
    });

    it('keeps treating Expires=0 as a session cookie', function (): void {
        $client = TestClient::boot(httpAppPath());

        $client->get('/jar/set', ['name' => 'token', 'value' => 't', 'path' => '/', 'expires' => '0']);

        expect($client->cookieJar())->toHaveCount(1)
            ->and($client->cookieJar()[0]->expiresAt)->toBeNull();
    });
});

describe('TestClient cookie jar SameSite', function (): void {
    beforeEach(function (): void {
        $this->client = TestClient::boot(httpAppPath());

        foreach (['Strict', 'Lax', 'None'] as $sameSite) {
            $this->client->get('https://app.example.test/jar/set', [
                'name' => strtolower($sameSite),
                'value' => '1',
                'path' => '/',
                'secure' => '1',
                'same_site' => $sameSite,
            ]);
        }

        $this->client->get('https://app.example.test/jar/set', ['name' => 'unset', 'value' => '1', 'path' => '/']);
    });

    it('withholds a SameSite=Strict cookie from a cross-site request', function (): void {
        $this->client->get('https://app.example.test/jar/x', headers: ['Origin' => 'https://evil.test'])
            ->assertJsonMissingPath('cookies.strict');
    });

    it('sends a SameSite=Lax cookie on a cross-site GET', function (): void {
        $this->client->get('https://app.example.test/jar/x', headers: ['Origin' => 'https://evil.test'])
            ->assertJsonPath('cookies.lax', '1');
    });

    it('withholds a SameSite=Lax cookie from a cross-site POST', function (): void {
        $this->client->post('https://app.example.test/jar/x', headers: ['Origin' => 'https://evil.test'])
            ->assertJsonMissingPath('cookies.lax');
    });

    it('sends SameSite=None and SameSite-less cookies on a cross-site POST', function (): void {
        $this->client->post('https://app.example.test/jar/x', headers: ['Origin' => 'https://evil.test'])
            ->assertJsonPath('cookies', ['none' => '1', 'unset' => '1']);
    });

    it('treats an Origin on a subdomain of the same site as same-site', function (): void {
        $this->client->post('https://app.example.test/jar/x', headers: ['Origin' => 'https://www.example.test'])
            ->assertJsonPath('cookies', ['strict' => '1', 'lax' => '1', 'none' => '1', 'unset' => '1']);
    });

    it('treats Sec-Fetch-Site: cross-site as a cross-site request', function (): void {
        $this->client->get('https://app.example.test/jar/x', headers: ['Sec-Fetch-Site' => 'cross-site'])
            ->assertJsonPath('cookies', ['lax' => '1', 'none' => '1', 'unset' => '1']);
    });

    it('sends Strict and Lax cookies on a same-site request', function (): void {
        $this->client->post('https://app.example.test/jar/x')
            ->assertJsonPath('cookies', ['strict' => '1', 'lax' => '1', 'none' => '1', 'unset' => '1']);
    });

    it('compares SameSite case-insensitively and treats unknown values as None', function (): void {
        $client = TestClient::boot(httpAppPath());
        $client->get('/jar/set', ['name' => 'strict', 'value' => '1', 'path' => '/', 'same_site' => 'strict']);
        $client->get('/jar/set', ['name' => 'odd', 'value' => '1', 'path' => '/', 'same_site' => 'Sideways']);

        $client->post('/jar/x', headers: ['Origin' => 'https://evil.test'])
            ->assertJsonPath('cookies', ['odd' => '1']);
    });

    it('treats Origin: null as a cross-site request', function (): void {
        $this->client->get('https://app.example.test/jar/x', headers: ['Origin' => 'null'])
            ->assertJsonMissingPath('cookies.strict');
    });

    it('lets Sec-Fetch-Site: same-site win over a cross-site Origin', function (): void {
        $this->client->post('https://app.example.test/jar/x', headers: [
            'Origin' => 'https://evil.test',
            'Sec-Fetch-Site' => 'same-site',
        ])->assertJsonPath('cookies', ['strict' => '1', 'lax' => '1', 'none' => '1', 'unset' => '1']);
    });

    it('applies a client-wide Origin set with withHeaders', function (): void {
        $this->client->withHeaders(['Origin' => 'https://evil.test'])
            ->post('https://app.example.test/jar/x')
            ->assertJsonPath('cookies', ['none' => '1', 'unset' => '1']);
    });
});

describe('TestClient cookie jar public suffixes', function (): void {
    it('ignores a Domain=co.uk cookie set by a.example.co.uk', function (): void {
        $client = TestClient::boot(httpAppPath());

        $client->get('http://a.example.co.uk/jar/set', [
            'name' => 'evil',
            'value' => 'e',
            'path' => '/',
            'domain' => 'co.uk',
        ])->assertCookie('evil');

        expect($client->cookieJar())->toBe([]);
        $client->get('http://other.co.uk/jar/x')->assertJsonPath('cookies', []);
    });

    it('ignores a Domain=com cookie set by example.com', function (): void {
        $client = TestClient::boot(httpAppPath());

        $client->get(
            'http://example.com/jar/set',
            ['name' => 'evil', 'value' => 'e', 'path' => '/', 'domain' => 'com'],
        );

        expect($client->cookieJar())->toBe([]);
    });

    it('stores a cookie for the registrable domain example.co.uk', function (): void {
        $client = TestClient::boot(httpAppPath());

        $client->get('http://a.example.co.uk/jar/set', [
            'name' => 'shared',
            'value' => 's',
            'path' => '/',
            'domain' => 'example.co.uk',
        ]);

        $client->get('http://b.example.co.uk/jar/x')->assertJsonPath('cookies', ['shared' => 's']);
    });

    it('stores a Domain=localhost cookie from localhost as host-only', function (): void {
        $client = TestClient::boot(httpAppPath());

        $client->get('/jar/set', ['name' => 'local', 'value' => 'l', 'path' => '/', 'domain' => 'localhost']);

        expect($client->cookieJar())->toHaveCount(1)
            ->and($client->cookieJar()[0]->hostOnly)->toBeTrue()
            ->and($client->cookieJar()[0]->domain)->toBe('localhost');
        $client->get('/jar/x')->assertJsonPath('cookies', ['local' => 'l']);
    });

    it('leaves an existing withCookie entry alone when a public-suffix cookie is ignored', function (): void {
        $client = TestClient::boot(httpAppPath())->withCookie('evil', 'kept');

        $client->get(
            'http://example.com/jar/set',
            ['name' => 'evil', 'value' => 'e', 'path' => '/', 'domain' => 'com'],
        );

        expect($client->cookies())->toBe(['evil' => 'kept']);
    });
});
