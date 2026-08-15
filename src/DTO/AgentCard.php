<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * An A2A Agent Card — the self-description a remote agent publishes at
 * `/.well-known/agent-card.json`, per the A2A protocol spec
 * (https://a2a-protocol.org).
 */
final readonly class AgentCard
{
    /**
     * @param array<string, mixed>         $capabilities e.g. ['streaming' => true, 'pushNotifications' => false]
     * @param array<int, array<string, mixed>> $skills   Each entry has at least id/name/description/tags
     * @param string[]                     $defaultInputModes
     * @param string[]                     $defaultOutputModes
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $url,
        public string $version,
        public ?string $protocolVersion,
        public array $capabilities,
        public array $skills,
        public array $defaultInputModes,
        public array $defaultOutputModes,
    ) {}

    public function supportsStreaming(): bool
    {
        return (bool) ($this->capabilities['streaming'] ?? false);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            protocolVersion: isset($data['protocolVersion']) ? (string) $data['protocolVersion'] : null,
            capabilities: $data['capabilities'] ?? [],
            skills: $data['skills'] ?? [],
            defaultInputModes: $data['defaultInputModes'] ?? [],
            defaultOutputModes: $data['defaultOutputModes'] ?? [],
        );
    }
}
