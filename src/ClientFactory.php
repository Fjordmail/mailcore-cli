<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli;

use Inboxcom\Mailcore\MailcoreClient;

/**
 * Builds a {@see MailcoreClient} from configuration, resolved in this order
 * (first non-empty wins):
 *
 *   1. Environment variables  MAILCORE_API_KEY / MAILCORE_BASE_URI
 *   2. INI config file        ~/.config/mailcore/config.ini  (or $XDG_CONFIG_HOME/mailcore/config.ini)
 *   3. Built-in default       (base URI only)
 *
 * The config file is a flat INI document:
 *
 *   api_key  = "your-api-key"
 *   base_uri = "https://api.example.com"
 *
 * Credentials are never taken from CLI flags, so the API key stays out of shell
 * history and `ps` output.
 */
final class ClientFactory
{
    /**
     * @param string|null $configFile Override the config-file path (mainly for tests);
     *                                 null uses the default XDG location.
     */
    public static function create(?string $configFile = null): MailcoreClient
    {
        $configFile ??= self::defaultConfigPath();
        $config = self::readConfig($configFile);

        $apiKey = self::env('MAILCORE_API_KEY') ?? self::stringOrNull($config['api_key'] ?? null);
        if ($apiKey === null) {
            throw new \RuntimeException(sprintf(
                'No Mailcore API key found. Set the MAILCORE_API_KEY environment variable, '
                . 'or add `api_key = "..."` to %s.',
                $configFile !== '' ? $configFile : '~/.config/mailcore/config.ini',
            ));
        }

        $baseUri = self::env('MAILCORE_BASE_URI') ?? self::stringOrNull($config['base_uri'] ?? null);

        return new MailcoreClient($apiKey, $baseUri ?? MailcoreClient::DEFAULT_BASE_URI);
    }

    /** Default config path: $XDG_CONFIG_HOME/mailcore/config.ini, falling back to ~/.config. */
    public static function defaultConfigPath(): string
    {
        $base = self::env('XDG_CONFIG_HOME');
        if ($base === null) {
            $home = self::env('HOME');
            if ($home === null) {
                return '';
            }
            $base = $home . '/.config';
        }

        return $base . '/mailcore/config.ini';
    }

    /** @return array<string, mixed> */
    private static function readConfig(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        // process_sections = false flattens, so both flat and [section] files work.
        $parsed = @parse_ini_file($path, false, INI_SCANNER_NORMAL);

        return is_array($parsed) ? $parsed : [];
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value !== false && trim($value) !== '' ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
