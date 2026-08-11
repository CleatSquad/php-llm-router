<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Fixtures;

use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Minimal PSR-16 cache for the Psr16*Store tests: an array with TTLs, plus a
 * $down switch so a test can simulate the backend (Memcached, filesystem, a
 * database) going away mid-run.
 */
final class ArrayPsr16Cache implements CacheInterface
{
    public bool $down = false;

    /** @var array<string, array{value: mixed, expiresAt: float|null}> */
    private array $entries = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $this->guard();

        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return $default;
        }

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= microtime(true)) {
            unset($this->entries[$key]);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->guard();

        $this->entries[$key] = [
            'value' => $value,
            'expiresAt' => is_int($ttl) ? microtime(true) + $ttl : null,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];
        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Raw write bypassing set(), for poisoning the backend the way an attacker
     * or a corrupted entry would.
     */
    public function poison(string $key, mixed $value): void
    {
        $this->entries[$key] = ['value' => $value, 'expiresAt' => null];
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new RuntimeException('PSR-16 backend unavailable');
        }
    }
}
