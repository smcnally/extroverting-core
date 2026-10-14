# Extroverting Core

WordPress plugin for Extroverting (multi-city local events calendar): city/event/retailer CPTs, passwordless OTP login, saved events, CRM consent, age gate, and a REST API under `extroverting/v1`. Brand-neutral — quiz logic lives in a separate pluggable provider.

## Install

The repo root **is** the plugin directory — clone it straight into `wp-content/plugins/extroverting-core/` and activate "Extroverting Core". Subsequent updates are a plain `git pull`. Requires WordPress 6.6+ and PHP 8.0+.

## Development

```bash
composer install
composer phpstan   # PHPStan level 5 with WordPress stubs
composer test      # PHPUnit via wp-phpunit (needs a local MySQL; see tests/wp-config.php)
```

CI (`.github/workflows/ci.yml`) runs PHPStan + PHPUnit on PHP 8.1 and 8.3 for every push to `main` and PR.

## License

GPL-2.0-or-later
