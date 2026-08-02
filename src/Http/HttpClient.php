<?php

declare(strict_types=1);

namespace LlmRouter\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin Guzzle wrapper shared by every driver. No base_uri so a single
 * instance can be reused across drivers pointing at different hosts.
 */
class HttpClient
{
    private Client $client;

    /**
     * Accepts an existing Guzzle client (e.g. a test double, or your app's own configured instance) instead of always creating a default one.
     */
    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout' => 5.0,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): string
    {
        try {
            $response = $this->client->request('GET', $url, $options);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function postJson(string $url, array $payload, array $options = []): string
    {
        $options['json'] = $payload;
        try {
            $response = $this->client->request('POST', $url, $options);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            return '';
        }
    }

    /**
     * @param mixed $body
     * @param array<string, mixed> $options
     */
    public function postRaw(string $url, $body, array $options = []): string
    {
        $options['body'] = $body;
        try {
            $response = $this->client->request('POST', $url, $options);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function post(string $url, array $options = []): string
    {
        try {
            $response = $this->client->request('POST', $url, $options);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            return '';
        }
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}
