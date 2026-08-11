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
     * Redis*Store classes actually call: string get()/setex(), and the
     * hash + expiry commands the atomic rate-limit counters use.
     *
     * Single-threaded like PHP itself, so it can't reproduce a true
     * race on its own; concurrency tests interleave calls from two
     * store instances sharing one connection, which is the interleaving
     * a read-modify-write implementation loses updates on.
     */
    class Redis
    {
        /** @var array<string, string> */
        private array $values = [];

        /** @var array<string, array<string, string>> */
        private array $hashes = [];

        public function get(string $key): string|false
        {
            return $this->values[$key] ?? false;
        }

        public function setex(string $key, int $ttl, string $value): bool
        {
            $this->values[$key] = $value;
            return true;
        }

        public function hGet(string $key, string $field): string|false
        {
            return $this->hashes[$key][$field] ?? false;
        }

        /**
         * @return array<string, string>
         */
        public function hGetAll(string $key): array
        {
            return $this->hashes[$key] ?? [];
        }

        public function hIncrBy(string $key, string $field, int $value): int
        {
            $current = (int) ($this->hashes[$key][$field] ?? 0);
            $next = $current + $value;
            $this->hashes[$key][$field] = (string) $next;

            return $next;
        }

        /**
         * @param array<string, int|string> $values
         */
        public function hMSet(string $key, array $values): bool
        {
            foreach ($values as $field => $value) {
                $this->hashes[$key][$field] = (string) $value;
            }

            return true;
        }

        public function expire(string $key, int $ttl): bool
        {
            return true;
        }

        public function del(string $key): int
        {
            unset($this->values[$key], $this->hashes[$key]);
            return 1;
        }
    }
}
