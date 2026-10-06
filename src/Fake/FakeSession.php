<?php

declare(strict_types=1);

namespace Marko\Testing\Fake;

use Marko\Session\Contracts\SessionInterface;
use Marko\Session\Flash\FlashBag;
use Random\RandomException;

class FakeSession implements SessionInterface
{
    public private(set) bool $started = false;

    public private(set) bool $regenerated = false;

    public private(set) bool $destroyed = false;

    public private(set) bool $saved = false;

    public private(set) bool $discarded = false;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $startedData = [];

    private string $id = '';

    private ?FlashBag $flashBag = null;

    public function start(): void
    {
        $this->started = true;
        $this->startedData = $this->data;
    }

    public function isModified(): bool
    {
        return $this->regenerated || $this->data !== $this->startedData;
    }

    public function discard(): void
    {
        $this->discarded = true;
        $this->started = false;
    }

    public function get(
        string $key,
        mixed $default = null,
    ): mixed {
        return $this->data[$key] ?? $default;
    }

    public function set(
        string $key,
        mixed $value,
    ): void {
        $this->data[$key] = $value;
    }

    public function has(
        string $key,
    ): bool {
        return array_key_exists($key, $this->data);
    }

    public function remove(
        string $key,
    ): void {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @throws RandomException
     */
    public function regenerate(
        bool $deleteOldSession = true,
    ): void {
        $this->regenerated = true;
        $this->id = bin2hex(random_bytes(16));
    }

    public function destroy(): void
    {
        $this->destroyed = true;
        $this->data = [];
    }

    /**
     * @throws RandomException
     */
    public function getId(): string
    {
        if ($this->id === '') {
            $this->id = bin2hex(random_bytes(16));
        }

        return $this->id;
    }

    public function setId(
        string $id,
    ): void {
        $this->id = $id;
    }

    public function flash(): FlashBag
    {
        if ($this->flashBag === null) {
            $this->flashBag = new FlashBag($this->data);
        }

        return $this->flashBag;
    }

    public function save(): void
    {
        $this->saved = true;
    }
}
