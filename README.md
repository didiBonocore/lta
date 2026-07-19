# Laravel Test Pattern Analyzer

A static-analysis tool that mines open-source Laravel applications and measures how their
automated test patterns (assertion density, mock breadth and chain depth, test size,
test-type distribution) evolved across Laravel major versions and across the pre/post-AI
development boundary. It is the artefact of an MSc dissertation.

**This is a CLI-only application.** There is no web UI. All interaction happens in the
terminal through Artisan commands; the emitted SQLite database *is* the dataset artefact.

## 1. Install

Requirements: PHP 8.4+, Composer, git (full clones are required — the pipeline never
shallow-clones), SQLite (bundled with PHP).

```sh
composer setup
```

This installs dependencies, creates `.env`, generates the app key, and migrates the SQLite
database at `database/database.sqlite`.

## 2. Prove the extraction core (fixture gate)

```sh
./vendor/bin/pest --group=fixtures
```

This must be green before anything else is trusted: it proves the extraction core
reproduces hand-computed values on the gold-standard fixtures AND that the PHPUnit and
Pest front-ends normalise the same test to identical IR. The full suite is
`./vendor/bin/pest --compact`.

## 3. Run the pilot

The whole pipeline over the pilot corpus, one repository at a time:

```sh
php artisan analyse:batch pilot.txt
```

`pilot.txt` lists one `owner/repo` per line (blank lines and `#` comments ignored). Per
repository the batch runs acquire → snapshot → extract → blame → verify, continues past
failures, prints a per-stage summary table, and exits non-zero if any repository failed.

## 4. The stages (runnable individually)

Discover everything with `php artisan list analyse`.

| Stage | Command | Purpose |
|-------|---------|---------|
| 0 | `analyse:acquire owner/repo` | Full `git clone` into `storage/corpus/` (never `--depth 1`); records HEAD sha, clone date, license and GitHub creation date. The corpus is read-only after this. |
| 1 | `analyse:snapshot owner/repo` | Instrument A — walks `composer.json` history and stores one representative commit per integer Laravel major (the last commit still resolving to that major). |
| 2–4 | `analyse:extract owner/repo [--head]` | Parses each snapshot's test suite (via `git show`, no checkouts) into the IR and writes one `test_observations` row per test method. Unparsable files land in `parse_failures`, never abort a run. `--head` extracts the working tree at HEAD instead. |
| B | `analyse:blame owner/repo [--cutoff=]` | Instrument B — attributes each test method of the newest extracted snapshot to its introducing commit's author-date (`git log -L` on the definition line range) and buckets it pre/post the AI cutoff (default `2022-11-30`; override via `ANALYSER_AI_CUTOFF` or `--cutoff=`). |
| 6 | `analyse:report [--metric=] [--cutoff=] [--export=]` | Descriptives (n, mean, median, sd, IQR) + OLS trend per major, Mann-Whitney U + Cliff's delta pre/post-AI, and test-type distribution tables. |
| — | `analyse:verify [owner/repo]` | Operator sanity checks: ascending majors, duplicates, orphan rows, per-snapshot counts, Instrument B coverage, parse-failure rate. Non-zero exit on hard failures. |
| — | `analyse:batch file` | All of the above per repository, serially, continue-on-failure. |

Mining stages only write to SQLite; `analyse:report` only reads. Every command is
idempotent — re-running replaces rows rather than duplicating them.

## 5. Report and export

```sh
php artisan analyse:report                                  # all five metrics
php artisan analyse:report --metric=assertion_count         # one metric
php artisan analyse:report --cutoff=2022-06-21              # cutoff sensitivity re-run
php artisan analyse:report --export=storage/app/report/pilot.csv
```

`--export` writes each block to its own CSV next to the given base path —
`<base>_descriptives.csv`, `<base>_regression.csv`, `<base>_ai_comparison.csv`,
`<base>_types_by_version.csv`, `<base>_types_by_window.csv` — so dissertation figures are
generated from command output, not screenshots. The statistical tests refuse (with a
warning, not a crash) when either AI window holds fewer than 5 methods.

## License

MIT.
