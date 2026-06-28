# inboxcom/mailcore-cli

[![Latest Version](https://img.shields.io/packagist/v/inboxcom/mailcore-cli.svg)](https://packagist.org/packages/inboxcom/mailcore-cli)
[![License](https://img.shields.io/packagist/l/inboxcom/mailcore-cli.svg)](LICENSE)

Command-line interface for the **Mailcore** mail-server control API, built on Symfony Console.

> **Unofficial.** Third-party tool; not published by Mailcore.

> **AI-assisted.** Code, tests, and docs were written largely by AI (Claude Code) under human direction and review.

Built on the [`inboxcom/mailcore-php`](https://packagist.org/packages/inboxcom/mailcore-php) core client.

## Install

As a project dependency:

```bash
composer require inboxcom/mailcore-cli
vendor/bin/mailcore list
```

…or globally, to get a `mailcore` command on your `PATH`:

```bash
composer global require inboxcom/mailcore-cli
```

Requires PHP >= 8.4.

## Usage

The CLI covers **every** API operation — one command per endpoint, grouped as `users:*`, `domains:*`, `mailboxplans:*`, `mailfilter:*`, `reports:*`, `datadump:*`. Run `mailcore list` to see them all.

```bash
export MAILCORE_API_KEY=your-api-key

mailcore users:list --filter='*' --limit=0,100
mailcore users:get holger@example.com          # detail view
mailcore users:add new@example.com 4           # prompts for the password
mailcore users:new-password new@example.com    # prompts for the password
mailcore domains:list
mailcore mailboxplans:list
mailcore mailfilter:rbl-lookup 8.8.8.8         # exit 1 if listed
mailcore mailfilter:cdl-lookup 8.8.8.8         # exit 1 if listed
mailcore datadump:fetch --output=dump.pgp
```

## Configuration

Credentials are resolved in this order (first non-empty wins):

1. Environment variables `MAILCORE_API_KEY` / `MAILCORE_BASE_URI`
2. INI file at `~/.config/mailcore/config.ini` (or `$XDG_CONFIG_HOME/mailcore/config.ini`)
3. Built-in default (base URI only)

```ini
; ~/.config/mailcore/config.ini   (see config.ini.example)
api_key  = "your-api-key"
base_uri = "https://api.example.com"   ; optional
```

```bash
mkdir -p ~/.config/mailcore
cp config.ini.example ~/.config/mailcore/config.ini
chmod 600 ~/.config/mailcore/config.ini   # keep the key private
```

Conventions:

- Credentials come from the environment or the config file above, **never CLI flags**, so the key stays out of shell history and `ps`.
- Passwords are read from a hidden prompt unless `--password=` is given, for the same reason.
- Predicate commands set a non-zero exit code on the "negative" result (`verify-password`, `rbl-lookup`) so they compose in scripts.
- List commands render tables; `datadump:fetch` streams raw bytes to stdout (or `--output` to a file).

## Development & full docs

This package is developed in the [`Fjordmail/mailcore-sdk`](https://github.com/Fjordmail/mailcore-sdk) monorepo (this repository is a read-only subtree split). Issues and contributions belong there.

## License

MIT — see [LICENSE](LICENSE).
