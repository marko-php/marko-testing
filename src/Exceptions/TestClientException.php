<?php

declare(strict_types=1);

namespace Marko\Testing\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class TestClientException extends MarkoException
{
    public static function unreadableUpload(
        string $path,
    ): self {
        return new self(
            message: "Cannot upload [$path]: the file does not exist or is not readable.",
            context: "withFile() was given the path $path",
            suggestion: 'Pass the path of an existing, readable file, e.g. a fixture under your tests directory.',
        );
    }

    public static function bodyAndData(
        string $method,
        string $uri,
    ): self {
        return new self(
            message: "Cannot send both form data and a raw body with $method $uri.",
            context: 'call() was given a non-empty $data array and a $body string.',
            suggestion: 'Pass the payload either as $data (form-encoded) or as a raw $body, not both. '
                . 'For JSON use postJson(), putJson(), patchJson() or deleteJson().',
        );
    }
}
