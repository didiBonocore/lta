# Laravel Test Pattern Analyser

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

## 3. Screen the candidates (build the corpus)

Corpus membership is decided by executing Appendix A's inclusion criteria, not by hand.
Screening produces the published decision log as a byproduct, and the corpus list is
generated from it — never typed.

```sh
php artisan analyse:batch --screen candidates.txt
```

acquires and screens every candidate, then stops: the tutorial/coursework/demonstration
criterion is human judgement and is never inferred. Answer it per candidate:

```sh
php artisan analyse:screen owner/repo --manual=exclude --reason="university coursework"
```

then finalise:

```sh
php artisan analyse:screen --finalise --export=decision-log.csv
```

Two criteria are pool-relative — candidates below the **first quartile of the candidate
pool** on test file proportion and test LOC proportion are excluded — so verdicts only
exist after `--finalise`, and re-finalising after the pool changes revises earlier
verdicts (the quartile moves; that is correct behaviour). `--finalise` refuses pools
smaller than 8, prints the computed quartiles, and writes the included set to `corpus.txt`
in the format `analyse:batch` consumes. A candidate with an unanswered manual criterion
finalises as `pending`, never as `included`. Candidates sharing git history (hard forks,
renamed upstreams) are detected by root-commit comparison and both halves are flagged for
the manual gate. `--export` writes the full decision log — every candidate, every
criterion outcome, every measured value — as the CSV published with the artefact.

## 4. Mine the corpus

The whole pipeline over the generated corpus (or `pilot.txt` for the pilot pair), one
repository at a time:

```sh
php artisan analyse:batch corpus.txt
```

The corpus file lists one `owner/repo` per line (blank lines and `#` comments ignored).
Per repository the batch runs acquire → snapshot → extract → blame → verify, continues
past failures, prints a per-stage summary table, and exits non-zero if any repository
failed.

## 5. The stages (runnable individually)

Discover everything with `php artisan list analyse`.

| Stage | Command | Purpose |
|-------|---------|---------|
| 0 | `analyse:acquire owner/repo` | Full `git clone` into `storage/corpus/` (never `--depth 1`); records HEAD sha, the default branch, clone date, licence, GitHub creation date, and the corpus's descriptive characteristics (stars, watchers, forks, archived status, fork provenance — recorded, never filtered on). The corpus is read-only after this. |
| S | `analyse:screen [owner/repo] [--manual= --reason=] [--finalise] [--export=]` | Candidate screening (section 3): executes Appendix A's criteria against the acquired clone, records every outcome and measured value in the `candidates` decision log, and generates `corpus.txt` at `--finalise`. |
| 1 | `analyse:snapshot owner/repo` | Instrument A — walks `composer.json` history **along the first-parent (trunk) line only** and stores one representative commit per integer Laravel major (the last trunk commit still resolving to that major, with its trunk position). A constraint that only ever existed on a feature branch is not a state the project was in; majors that disappear from trunk are pruned with their observations. |
| 2–4 | `analyse:extract owner/repo [--head]` | Routes each test file to a front end on its parse tree, parses each snapshot's suite (via `git show`, no checkouts) into the IR, and writes one `test_observations` row per test method. Unparsable files land in `parse_failures`; files that parse but no front end owns (e.g. Codeception) land in `unroutable_files` with their detected base class. Neither aborts a run. `--head` extracts the working tree at HEAD instead. |
| B | `analyse:blame owner/repo [--cutoff=]` | Instrument B — attributes each test method of the newest extracted snapshot to its introducing commit's author-date (`git log -L` on the definition line range) and buckets it pre/post the AI cutoff (default `2022-06-21`; override via `ANALYSER_AI_CUTOFF` or `--cutoff=`). |
| 6 | `analyse:report [--metric=] [--cutoff=] [--export=]` | Descriptives (n, mean, median, sd, IQR) + OLS trend per major, Mann-Whitney U + Cliff's delta pre/post-AI, and test-type distribution tables. |
| — | `analyse:verify [owner/repo]` | Operator sanity checks: provenance (HEAD + branch), ascending majors, trunk-position monotonicity (a genuine constraint oscillation warns), duplicates, orphan rows, per-snapshot counts, files-but-zero-observations (the excluded-framework signature), Instrument B coverage, parse-failure rate, unroutable counts per checkpoint. Non-zero exit on hard failures. |
| — | `analyse:batch file [--screen]` | The mining stages per repository, serially, continue-on-failure. `--screen` runs acquire + screen only, stopping at the manual gate (section 3). |

Mining stages only write to SQLite; `analyse:report` only reads. Every command is
idempotent — re-running replaces rows rather than duplicating them.

## 6. Report and export

```sh
php artisan analyse:report                                       # all metrics
php artisan analyse:report --metric=test_assertion_count        # one metric
php artisan analyse:report --cutoff=2022-06-21                   # cutoff sensitivity re-run
php artisan analyse:report --export=storage/app/report/pilot.csv
```

`--export` writes each block to its own CSV next to the given base path —
`<base>_descriptives.csv`, `<base>_regression.csv`, `<base>_ai_comparison.csv`,
`<base>_types_by_version.csv`, `<base>_types_by_window.csv` — so dissertation figures are
generated from command output, not screenshots. The statistical tests refuse (with a
warning, not a crash) when either AI window holds fewer than 5 methods.

## License

MIT.
