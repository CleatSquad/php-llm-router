<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Auth;

use Countable;

final class ApiKeyPool implements Countable
{
    /** @var list<string> */
    private array $keys = [];
    private int $currentIndex = 0;

    /**
     * @param string|array<mixed> $keys A single key, a comma-separated list of keys, or an array of keys.
     */
    public function __construct(string|array $keys)
    {
        $rawKeys = is_array($keys) ? $keys : (str_contains($keys, ',') ? explode(',', $keys) : [$keys]);
        foreach ($rawKeys as $key) {
            if (is_string($key)) {
                $trimmed = trim($key);
                if ($trimmed !== '') {
                    $this->keys[] = $trimmed;
                }
            }
        }
    }

    public function isEmpty(): bool
    {
        return empty($this->keys);
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * Round-robin: returns the next API key, looping back at the end of the array.
     * Returns an empty string if the pool is empty.
     */
    public function next(): string
    {
        if (empty($this->keys)) {
            return '';
        }

        $key = $this->keys[$this->currentIndex];
        $this->currentIndex = ($this->currentIndex + 1) % count($this->keys);

        return $key;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->keys;
    }
}
