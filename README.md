# Extroverting Core

L0 core WordPress plugin for Extroverting, a multi-city local events calendar product.

## What this is

Extroverting Core is the brand-neutral foundation layer for Extroverting, a multi-city local events calendar. It provides the city, event, and retailer custom post types plus an event category taxonomy, passwordless OTP authentication (a single field accepts an email or mobile number), saved events, routes, CRM consent recording, an on-demand age gate, and a REST API under `extroverting/v1`. It contains no quiz logic; that lives in a separate pluggable provider.

## Installation

Copy the `extroverting-core/` subdirectory into your site's `wp-content/plugins/` directory, then activate "Extroverting Core" from the WordPress plugins screen.

- Requires WordPress 6.6 or later.
- Requires PHP 8.0 or later.

## Development

Install dependencies and run the checks:

```bash
composer install
composer phpstan
composer test
```

`composer phpstan` runs PHPStan at level 5 with the `szepeviktor/phpstan-wordpress` extension. `composer test` runs PHPUnit against WordPress 6.x via wp-phpunit (roots/wordpress) and a MySQL service; tests read `WP_DB_NAME`, `WP_DB_USER`, `WP_DB_PASS`, and `WP_DB_HOST` from `tests/wp-config.php`.

Continuous integration is defined in `.github/workflows/ci.yml`. On push to `main` and on pull requests it runs PHPStan (level 5) and PHPUnit (wp-phpunit against WP 6.x with a MySQL service) on PHP 8.1 and 8.3.

## License

GPL-2.0-or-later
