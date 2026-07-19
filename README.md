# Laravel Test Pattern Analyzer

A static-analysis tool that mines open-source Laravel applications and measures how their
automated test patterns (assertion density, mock breadth and chain depth, test size,
test-type distribution) evolved across Laravel major versions and across the pre/post-AI
development boundary. It is the artefact of an MSc dissertation.

**This is a CLI-only application.** There is no web UI. All interaction happens in the
terminal through Artisan commands; the emitted SQLite database *is* the dataset artefact.

## Requirements

- PHP 8.4+, Composer, git (full clones are required — the pipeline never shallow-clones)
- SQLite (bundled with PHP)

## Setup

```sh
composer setup
```

This installs dependencies, creates `.env`, generates the app key, and migrates the
SQLite database.

## The pipeline

Discover the five commands with `php artisan list analyse`. Run them in order per repository:

| Stage | Command | Purpose |
|-------|-------------------------------------------|---------|
| 0 | `analyse:acquire owner/repo` | Full `git clone` into `storage/corpus/` (never `--depth 1`); records HEAD sha, clone date, license and GitHub creation date. |
| 1 | `analyse:snapshot owner/repo` | Instrument A — mines `composer.json` history and stores one representative commit per integer Laravel major. |
| 2–4 | `analyse:extract owner/repo [--head]` | Parses each snapshot's test suite (via `git show`, no checkouts) into the IR and writes one `test_observations` row per test method. |
| B | `analyse:blame owner/repo [--cutoff=]` | Instrument B — attributes each test method to its introducing commit's author-date and buckets it pre/post the AI cutoff (2022-06-21, Copilot GA; configured in `config/analyser.php`). |
| 6 | `analyse:report [--metric=] [--sensitivity]` | Descriptives + per-major trend (Instrument A) and the Mann-Whitney U / Cliff's delta pre/post-AI comparison (Instrument B). |

Mining stages only write to SQLite; `analyse:report` only reads from it. Every command is
idempotent — re-running replaces rows rather than duplicating them. The corpus under
`storage/corpus/` is treated as read-only after acquisition.

## Tests

```sh
./vendor/bin/pest --compact          # full suite
./vendor/bin/pest --group=fixtures   # the extraction-core ground-truth gate
```

The fixture group proves the extraction core reproduces hand-computed values and that the
PHPUnit and Pest front-ends normalise to identical IR. It must stay green at all times.

## License

MIT.
