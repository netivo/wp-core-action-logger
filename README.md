# Netivo Action Logger Module for WordPress

This module captures administrative actions on WordPress websites and streams them asynchronously to the Netivo Action Logger central API server.

## Features
- **Zero UI Disruption**: Uses non-blocking asynchronous HTTP requests with local queue fallback (`wp_nt_action_log_queue`).
- **Granular Coverage**: Auth events, user management, plugin/theme changes, core updates, settings, posts/pages, and WooCommerce orders/products.
- **Hardcoded Configuration**: Controlled via `wp-config.php` constants or theme filters to prevent client tampering.
- **Admin Status View**: Displays real-time connection health, token verification, and queue backlog.

## Configuration
Add the following to `wp-config.php` or theme functions:
```php
define( 'NETIVO_ACTION_LOGGER_API_URL', 'https://logger.netivo.pl' );
define( 'NETIVO_ACTION_LOGGER_API_KEY', 'nt_live_xxxxxxxxxxxxxxxxxxxxxxxx' );
```
