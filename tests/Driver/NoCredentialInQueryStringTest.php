<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use PHPUnit\Framework\TestCase;

/**
 * A credential in a query string travels wherever the URL does: proxy access
 * logs, Guzzle exception messages, anything that quotes the request line. Every
 * provider here accepts a header instead, so no driver has a reason to do it —
 * and the one that did was found only after the same bug had been fixed next
 * door in GeminiDriver. This test is what makes that a class of bug rather than
 * an inventory to re-run by hand.
 */
final class NoCredentialInQueryStringTest extends TestCase
{
    private const CREDENTIAL_KEYS = ['key', 'api_key', 'apikey', 'token', 'access_token', 'password', 'secret'];

    public function testNoDriverSendsACredentialAsAQueryParameter(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            $source = (string) file_get_contents($path);

            // Each 'query' => [ ... ] option handed to Guzzle, non-greedy so
            // adjacent options are not swallowed into one match.
            preg_match_all("/'query'\s*=>\s*\[(.*?)]/s", $source, $matches);

            foreach ($matches[1] as $queryBlock) {
                foreach (self::CREDENTIAL_KEYS as $credential) {
                    if (preg_match("/['\"]" . preg_quote($credential, '/') . "['\"]\s*=>/i", $queryBlock) === 1) {
                        $offenders[] = sprintf('%s sends "%s" in the query string', basename($path), $credential);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Credentials belong in a header, never in a URL:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src');

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertNotEmpty($files, 'No sources scanned — the test would pass vacuously.');

        return $files;
    }
}
