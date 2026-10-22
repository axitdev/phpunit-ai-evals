# Contributing

Issues and pull requests are welcome.

## Setup

```bash
composer install
composer test      # PHPUnit
composer phpstan   # PHPStan, level max
composer cs        # PHP CS Fixer; composer cs-fix applies it
```

All three run in CI and must pass. CI also reports line coverage, and on pull requests it runs mutation testing (`composer infection`, which needs pcov or Xdebug locally). Mutation testing doesn't fail the build, but read the escaped mutants it reports in new code: one that changes behaviour means a missing test.

## Live provider checks

`tests/Live` calls the real Anthropic, Gemini and OpenAI APIs to check that the parsers match what the providers send. It isn't part of `composer test`. If you change a parser or the stream handling, run it with the keys you have:

```bash
ANTHROPIC_API_KEY=… GEMINI_API_KEY=… OPENAI_API_KEY=… vendor/bin/phpunit tests/Live
```

Providers without a key are skipped. Update the [provider support table](docs/how-it-works.md#provider-support) with what you verified.

## Adding a provider

Implement `ProviderPayloadParserInterface`, including `requestPayload()` for anything the provider keeps in the URL rather than the body (model, streaming), add it to `AiVcrClient::defaultParsers()`, and add parser tests in `tests/Unit/PayloadParsersTest.php` built from a real response. If it streams, teach `StreamRebuilder` to merge and render its chunks.

## Changes to hashing

Anything that changes how requests are normalized or hashed makes existing cassettes miss. Call it out in `CHANGELOG.md` and say which cassettes need re-recording.
