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
- Maintain 100% backward compatibility within a major release.
- Update `README.md` and add a `CHANGELOG.md` entry when public behavior or strategies change.
- **Version numbers live in `CHANGELOG.md` and `UPGRADE.md`, not in `README.md`.**
  The README says what the package does, in the present tense; those two files
  say what changed and when. The install command carries no constraint —
  `composer require cleatsquad/php-llm-router` — so Composer resolves the
  current release and writes the constraint itself. A version written by hand
  into the README is wrong at the next release and nobody notices. The one
  exception is the required PHP version, which is a real constraint on the
  reader.

## Backward compatibility

Anything under `src/` that is `public` is part of the public API and follows [Semantic Versioning](https://semver.org). Breaking changes require a major release.
