<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli\Tests;

use GuzzleHttp\Client as GuzzleClient;
use Inboxcom\Mailcore\Cli\ClientFactory;
use Inboxcom\Mailcore\Http\Transport;
use Inboxcom\Mailcore\MailcoreClient;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    private ?string $apiKey = null;
    private ?string $baseUri = null;
    private ?string $timeout = null;
    private ?string $connectTimeout = null;
    private string $missingConfig = '/nonexistent/mailcore/config.ini';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        // Preserve the ambient environment so the suite stays order-independent.
        $this->apiKey = getenv('MAILCORE_API_KEY') ?: null;
        $this->baseUri = getenv('MAILCORE_BASE_URI') ?: null;
        $this->timeout = getenv('MAILCORE_TIMEOUT') ?: null;
        $this->connectTimeout = getenv('MAILCORE_CONNECT_TIMEOUT') ?: null;
        putenv('MAILCORE_API_KEY');
        putenv('MAILCORE_BASE_URI');
        putenv('MAILCORE_TIMEOUT');
        putenv('MAILCORE_CONNECT_TIMEOUT');
    }

    protected function tearDown(): void
    {
        $this->restore('MAILCORE_API_KEY', $this->apiKey);
        $this->restore('MAILCORE_BASE_URI', $this->baseUri);
        $this->restore('MAILCORE_TIMEOUT', $this->timeout);
        $this->restore('MAILCORE_CONNECT_TIMEOUT', $this->connectTimeout);
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

    public function testTimeoutsDefaultWhenUnset(): void
    {
        $config = self::guzzleConfig(ClientFactory::create($this->writeConfig('api_key = "k"')));

        self::assertSame(MailcoreClient::DEFAULT_TIMEOUT, $config['timeout']);
        self::assertSame(MailcoreClient::DEFAULT_CONNECT_TIMEOUT, $config['connect_timeout']);
    }

    public function testTimeoutsReadFromConfigFile(): void
    {
        $config = self::guzzleConfig(ClientFactory::create($this->writeConfig(<<<INI
            api_key         = "k"
            timeout         = 45
            connect_timeout = 4
            INI)));

        self::assertSame(45.0, $config['timeout']);
        self::assertSame(4.0, $config['connect_timeout']);
    }

    public function testTimeoutEnvOverridesConfigFile(): void
    {
        putenv('MAILCORE_TIMEOUT=12.5');
        $config = self::guzzleConfig(ClientFactory::create($this->writeConfig(<<<INI
            api_key = "k"
            timeout = 99
            INI)));

        self::assertSame(12.5, $config['timeout']);
    }

    /** @return array<string, mixed> The default Guzzle client's merged config. */
    private static function guzzleConfig(MailcoreClient $client): array
    {
        $transport = (new \ReflectionProperty(MailcoreClient::class, 'transport'))->getValue($client);
        \assert($transport instanceof Transport);
        $http = (new \ReflectionProperty(Transport::class, 'httpClient'))->getValue($transport);
        self::assertInstanceOf(GuzzleClient::class, $http);

        /** @var array<string, mixed> $config */
        $config = (new \ReflectionProperty(GuzzleClient::class, 'config'))->getValue($http);

        return $config;
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
