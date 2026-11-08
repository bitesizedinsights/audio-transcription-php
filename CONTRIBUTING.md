# Contributing

1. Fork the repository and run `composer install`.
2. Make your change and add or update tests in `tests/`.
3. Run `composer check` (Pint, PHPStan level 8, Pest); all three must pass.
4. Open a pull request with a short description of the change.

## Live test

One opt-in test transcribes a real recording through the Actor and costs a few cents. It is skipped unless `APIFY_LIVE_TEST=1` is set and a token is present in `APIFY_API_TOKEN` or `APIFY_TOKEN`. The sample file is fetched on the first run and kept in the system temp directory.

```bash
APIFY_LIVE_TEST=1 APIFY_API_TOKEN=your_token composer test -- tests/LiveTest.php
```

## Continuous integration

CI (`.github/workflows/tests.yml`) runs tests, Pint and PHPStan on push and pull requests. The live test runs only when the Tests workflow is triggered manually: add an `APIFY_API_TOKEN` repository secret, then use "Run workflow" with `live` ticked.
