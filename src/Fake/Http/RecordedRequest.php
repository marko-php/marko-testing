<?php

declare(strict_types=1);

namespace Marko\Testing\Fake\Http;

use JsonException;
use Marko\Http\RequestOptions;

/**
 * A request captured by FakeHttpClient.
 */
readonly class RecordedRequest
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $options = [],
    ) {}

    /**
     * The value of a request header (case-insensitive), or null when absent.
     * Multiple values are joined with ", ".
     */
    public function header(
        string $name,
    ): ?string {
        $headers = $this->options[RequestOptions::HEADERS] ?? [];

        if (!is_array($headers)) {
            return null;
        }

        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return null;
    }

    /**
     * The 'json' option as passed to the client, or null when absent.
     */
    public function json(): mixed
    {
        return $this->options[RequestOptions::JSON] ?? null;
    }

    /**
     * The request body: the raw 'body', the encoded 'json', or the encoded 'form_params'.
     *
     * @throws JsonException
     */
    public function body(): string
    {
        $options = $this->options;

        if (array_key_exists(RequestOptions::BODY, $options)) {
            return (string) $options[RequestOptions::BODY];
        }

        if (array_key_exists(RequestOptions::JSON, $options)) {
            return json_encode($options[RequestOptions::JSON], JSON_THROW_ON_ERROR);
        }

        if (is_array($options[RequestOptions::FORM_PARAMS] ?? null)) {
            return http_build_query($options[RequestOptions::FORM_PARAMS]);
        }

        return '';
    }
}
