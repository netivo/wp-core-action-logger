# Netivo Action Logger Module for WordPress

This module captures administrative actions on WordPress websites and streams them to the Netivo Action Logger central API. It is a Composer module for Netivo themes (`netivo/wp-core`).

## Features
- **Zero UI Disruption**: Events are buffered during the request and sent once on `shutdown`, after the response has been delivered (PHP-FPM/LiteSpeed). Failed deliveries fall back to a local queue (`wp_nt_action_log_queue`) drained by WP-Cron.
- **Granular Coverage**: Auth events, user management, plugin/theme/core changes, critical settings, posts/pages/media/terms, and WooCommerce orders, products, coupons and settings.
- **Privacy by default**: Passwords, tokens, cookies and card numbers are redacted before anything leaves the site; post content and customer details are never sent.
- **Hardcoded Configuration**: Controlled via `wp-config.php` constants or the theme configuration to prevent client tampering; there is no settings form.
- **Admin Status View**: Settings → Action Logger shows connection health, the masked key, queue backlog and the last error, with a test ping.

## Requirements
- PHP 8.2 – 8.5
- WordPress 6.3+
- `netivo/wp-core` ^1.1 (PHP 8.2/8.3 sites resolve 1.2.x; 1.3+ requires PHP 8.4)

## Installation

The package and its `netivo/wp-core` dependency are distributed from Netivo's Git repositories. Composer ignores the `repositories` of dependencies, so the theme's own `composer.json` must list both:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:netivo/wp-core-action-logger.git" },
    { "type": "vcs", "url": "git@github.com:netivo/wp-core.git" }
]
```

```bash
composer require netivo/wp-core-action-logger
```

Releases are the `v*.*.*` tags mirrored from the monorepo; `composer.json` deliberately has no `version` field, so the tag is the version.

Then create the module once from the theme, e.g. in `functions.php` or the theme class:

```php
new \Netivo\Module\ActionLogger\Module();
```

The module resolves its configuration on `after_setup_theme`. When it is disabled or misconfigured it captures nothing, and the status page explains why.

## Configuration
Use the values from the dashboard's **Add site** dialog. In `wp-config.php` (highest priority):

```php
define( 'NETIVO_ACTION_LOGGER_API_URL', 'https://logger.netivo.pl' );
define( 'NETIVO_ACTION_LOGGER_API_KEY', 'nt_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
```

Or in the theme configuration (`<theme>/config/main.config.php`):

```php
return [
    'action_logger' => [
        'api_url' => 'https://logger.netivo.pl',
        'api_key' => 'nt_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'enabled' => true,
    ],
];
```

| Setting | Constant | Default | |
|---|---|---|---|
| `api_url` | `NETIVO_ACTION_LOGGER_API_URL` | — | API origin; `/api/v1` is appended when missing |
| `api_key` | `NETIVO_ACTION_LOGGER_API_KEY` | — | Site token (`nt_live_` + 32 hex characters) |
| `enabled` | `NETIVO_ACTION_LOGGER_ENABLED` | `true` | Kill switch |
| `delivery` | `NETIVO_ACTION_LOGGER_DELIVERY` | `shutdown` | `shutdown` or `queue` (see below) |
| `timeout` | `NETIVO_ACTION_LOGGER_TIMEOUT` | `3` | HTTP timeout in seconds (1–10) |
| `trusted_proxies` | `NETIVO_ACTION_LOGGER_TRUSTED_PROXIES` | `[]` | Proxy IPs whose `X-Forwarded-For` is trusted for the client IP |

Priority, highest first: constants, theme configuration, `theme_options` (`action_logger_api_url`, `action_logger_api_key`, `action_logger_enabled`), then the `netivo_action_logger_config` filter.

### Delivery modes
- **`shutdown`** (default): events are sent at the end of the request with a blocking request whose result is checked; failures are queued and retried by WP-Cron every 5 minutes. Under PHP-FPM or LiteSpeed the response is finished first, so visitors never wait.
- **`queue`**: every event is queued and only WP-Cron sends. Use this on hosts without PHP-FPM (e.g. Apache mod_php), where an unreachable API could otherwise add up to `timeout` seconds to the end of a request.

WP-Cron runs on page visits; on low-traffic sites consider triggering `wp-cron.php` from a system cron.

## Hooks
| Filter | Purpose |
|---|---|
| `netivo_action_logger_config` | Lowest-priority configuration values |
| `netivo_action_logger_ignored_post_types` | Post types the content listener skips |
| `netivo_action_logger_tracked_options` | Options (name => severity) the settings listener logs |

## Development
```bash
composer install
composer test           # PHPUnit + Brain Monkey
composer phpstan        # PHPStan level 7
composer lint           # WordPress VIP coding standards
composer compatibility  # PHPCompatibility 8.2 – 8.5
```

See [`docs/architecture/wordpress-module.md`](../../docs/architecture/wordpress-module.md) for the full list of tracked events.
