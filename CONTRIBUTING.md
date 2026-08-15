# Contributing

Contributions are welcome — bug reports, documentation, and code alike.

## Getting started

```bash
git clone https://github.com/CleatSquad/php-llm-router.git
cd php-llm-router
composer install
```

## Before opening a pull request

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan analysis, must report no errors
```

## Guidelines

- Follow PSR-12 / PSR-1. Match the style of the surrounding code.
- Every behavior change needs a unit test in `tests/`.
- Keep the public API small and focused.
- Maintain 100% backward compatibility within major releases (`v4.x`).
- Update `README.md` and add a `CHANGELOG.md` entry when public behavior or strategies change.

## Backward compatibility

Anything under `src/` that is `public` is part of the public API and follows [Semantic Versioning](https://semver.org). Breaking changes require a major release.
