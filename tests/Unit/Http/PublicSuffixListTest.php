<?php

declare(strict_types=1);

use Marko\Testing\Http\PublicSuffixList;

describe('PublicSuffixList', function (): void {
    it('treats every single-label domain as a public suffix', function (string $domain): void {
        expect(PublicSuffixList::isPublicSuffix($domain))->toBeTrue();
    })->with(['com', 'net', 'org', 'uk', 'test', 'localhost']);

    it('treats common multi-label suffixes such as co.uk as public suffixes', function (string $domain): void {
        expect(PublicSuffixList::isPublicSuffix($domain))->toBeTrue();
    })->with(['co.uk', 'org.uk', 'com.au', 'co.jp', 'github.io']);

    it('does not treat a registrable domain as a public suffix', function (string $domain): void {
        expect(PublicSuffixList::isPublicSuffix($domain))->toBeFalse();
    })->with(['example.com', 'example.co.uk', 'shop.example.test', 'acme.github.io']);

    it('never treats an IP address as a public suffix', function (string $address): void {
        expect(PublicSuffixList::isPublicSuffix($address))->toBeFalse();
    })->with(['127.0.0.1', '[::1]', '::1']);

    it('returns the registrable domain as the site of a host', function (string $host, string $site): void {
        expect(PublicSuffixList::site($host))->toBe($site);
    })->with([
        ['example.com', 'example.com'],
        ['shop.example.com', 'example.com'],
        ['a.b.example.co.uk', 'example.co.uk'],
        ['shop.example.test', 'example.test'],
        ['acme.github.io', 'acme.github.io'],
    ]);

    it('returns the host itself as the site of a public suffix host or an IP address', function (string $host): void {
        expect(PublicSuffixList::site($host))->toBe($host);
    })->with(['localhost', 'co.uk', '127.0.0.1', '[::1]']);
});
