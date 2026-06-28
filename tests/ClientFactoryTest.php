<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli\Tests;

use Inboxcom\Mailcore\Cli\ClientFactory;
use Inboxcom\Mailcore\MailcoreClient;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    private ?string $apiKey = null;
    private ?string $baseUri = null;
    private string $missingConfig = '/nonexistent/mailcore/config.ini';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        // Preserve the ambient environment so the suite stays order-independent.
        $this->apiKey = getenv('MAILCORE_API_KEY') ?: null;
        $this->baseUri = getenv('MAILCORE_BASE_URI') ?: null;
        putenv('MAILCORE_API_KEY');
        putenv('MAILCORE_BASE_URI');
    }

    protected function tearDown(): void
    {
        $this->restore('MAILCORE_API_KEY', $this->apiKey);
        $this->restore('MAILCORE_BASE_URI', $this->baseUri);
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testThrowsWhenNoApiKeyFromEnvOrConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MAILCORE_API_KEY|api_key/');

        ClientFactory::create($this->missingConfig);
    }

    public function testReadsApiKeyFromEnvironment(): void
    {
        putenv('MAILCORE_API_KEY=env-key');

        // A missing config file must not stop env-based credentials from working.
        self::assertInstanceOf(MailcoreClient::class, ClientFactory::create($this->missingConfig));
    }

    public function testReadsApiKeyFromConfigFileWhenEnvMissing(): void
    {
        $config = $this->writeConfig(<<<INI
            api_key  = "file-key"
            base_uri = "https://mail.example.test"
            INI);

        self::assertInstanceOf(MailcoreClient::class, ClientFactory::create($config));
    }

    public function testEnvironmentTakesPrecedenceOverConfigFile(): void
    {
        putenv('MAILCORE_API_KEY=env-wins');
        $config = $this->writeConfig('api_key = "file-key"');

        // Both present: resolution must not error and env is preferred (no observable
        // difference here beyond "it builds", since the key is not exposed by the client).
        self::assertInstanceOf(MailcoreClient::class, ClientFactory::create($config));
    }

    public function testSectionedConfigFileIsAlsoAccepted(): void
    {
        $config = $this->writeConfig(<<<INI
            [mailcore]
            api_key = "sectioned-key"
            INI);

        self::assertInstanceOf(MailcoreClient::class, ClientFactory::create($config));
    }

    public function testDefaultConfigPathHonoursXdgConfigHome(): void
    {
        $xdg = getenv('XDG_CONFIG_HOME') ?: null;
        try {
            putenv('XDG_CONFIG_HOME=/tmp/xdg-test');
            self::assertSame('/tmp/xdg-test/mailcore/config.ini', ClientFactory::defaultConfigPath());
        } finally {
            $this->restore('XDG_CONFIG_HOME', $xdg);
        }
    }

    private function writeConfig(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mailcore-cfg-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function restore(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
        } else {
            putenv("{$name}={$value}");
        }
    }
}
