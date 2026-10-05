<?php

declare(strict_types=1);

use Marko\Testing\Fake\Http\RecordedRequest;

describe('RecordedRequest', function (): void {
    it('exposes method, url and options', function (): void {
        $request = new RecordedRequest('POST', 'https://api.example.com/orders', ['json' => ['id' => 1]]);

        expect($request->method)->toBe('POST')
            ->and($request->url)->toBe('https://api.example.com/orders')
            ->and($request->options)->toBe(['json' => ['id' => 1]]);
    });

    it('returns a header value case-insensitively or null when absent', function (): void {
        $request = new RecordedRequest('GET', 'https://api.example.com', [
            'headers' => [
                'X-Api-Key' => 'secret',
                'Accept' => ['application/json', 'text/plain'],
            ],
        ]);

        expect($request->header('x-api-key'))->toBe('secret')
            ->and($request->header('ACCEPT'))->toBe('application/json, text/plain')
            ->and($request->header('X-Missing'))->toBeNull()
            ->and(new RecordedRequest('GET', 'https://api.example.com')->header('Accept'))->toBeNull();
    });

    it('returns the json option from json()', function (): void {
        $request = new RecordedRequest('POST', 'https://api.example.com', ['json' => ['id' => 1]]);

        expect($request->json())->toBe(['id' => 1])
            ->and(new RecordedRequest('GET', 'https://api.example.com')->json())->toBeNull();
    });

    it('returns the raw body, encoded json, or encoded form params from body()', function (): void {
        expect(new RecordedRequest('POST', 'https://a.test', ['body' => 'raw'])->body())->toBe('raw')
            ->and(new RecordedRequest('POST', 'https://a.test', ['json' => ['id' => 1]])->body())->toBe('{"id":1}')
            ->and(new RecordedRequest('POST', 'https://a.test', ['form_params' => ['a' => 'b', 'c' => 'd']])->body())
            ->toBe('a=b&c=d')
            ->and(new RecordedRequest('GET', 'https://a.test')->body())->toBe('');
    });
});
