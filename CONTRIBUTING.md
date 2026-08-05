# Contributing

1. Fork the repository and create a focused branch.
2. Run `composer install`.
3. Add or update tests for every behavior change.
4. Run `composer check` before opening a pull request.

Keep public APIs backward compatible within a major release. Never commit real Elvesora API keys or customer enrichment data. The automated tests use mocked HTTP responses and send no requests to the live API.
