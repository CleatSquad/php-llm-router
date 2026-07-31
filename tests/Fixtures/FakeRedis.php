<?php

declare(strict_types=1);

// Deliberately global-namespaced and conditionally declared: the
// Redis*Store classes type-hint the real ext-redis \Redis class, and
// this stub exists only to satisfy that type hint in environments
// (like CI) where the extension isn't installed. If ext-redis *is*
// loaded, this file is a no-op and the tests run against the real
// thing instead.
if (!class_exists('Redis', false)) {
    /**
     * In-memory stand-in for the subset of phpredis's Redis class the
     * Redis*Store classes actually call: get() and setex().
     */
    class Redis
    {
        /** @var array<string, string> */
        private array $values = [];

        public function get(string $key): string|false
        {
            return $this->values[$key] ?? false;
        }

        public function setex(string $key, int $ttl, string $value): bool
        {
            $this->values[$key] = $value;
            return true;
        }
    }
}
