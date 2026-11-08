# Contributing

1. Fork the repository and run `composer install`.
2. Make your change and add or update tests in `tests/`.
3. Run `composer check` (Pint, PHPStan level 8, Pest); all three must pass.
4. Open a pull request with a short description of the change.

CI (`.github/workflows/tests.yml`) runs tests, Pint and PHPStan on push and pull requests. The live test is manual only (`workflow_dispatch`, needs the `APIFY_API_TOKEN` secret).
