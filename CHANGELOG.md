# Changelog

All notable changes to the Laravel Test Pattern Analyser will be documented in this file.

## [v0.3.0] - 2026-08-22

### Breaking Changes
- **Dataset Schema Update**: Added `agent_authored`, `agent_tool`, `tool_version`, and `mock_breadth_excluding_facades` columns to `test_observations`. The extraction-level columns cannot be backfilled without re-parsing, so previously mined datasets are incompatible and must be re-mined. Rationale: closes Appendix B's three previously uncomputable variables (Aa agent attribution, Tv tool version) and the facade-fake-excluded mock breadth H2a commits to.
- **analyse:report rewired to the declared statistical design**: Comparisons now use a per-repository summary statistic per group. Trend (H2a/H2b) is tested by the Wilcoxon signed-rank of per-repository Theil-Sen slopes against zero (Mann-Kendall tau descriptive per repository, Spearman secondary); the era comparison (H3a) by the Wilcoxon signed-rank over per-repository pre/post window medians, split by paradigm. The pooled OLS fit is demoted to a labelled descriptive fit (CSV block renamed `regression` → `descriptive_fit`) and the pooled Mann-Whitney U to a labelled secondary. Rationale: the previous pooled tests violated the independence assumption the design declares against.

### Added
- **H1 paradigm block**: Appendix B's categorical Pf per checkpoint (0 = PHPUnit only, 1 = mixed, 2 = Pest only; undefined checkpoints reported, never coerced to 0), descriptive per major plus the boundary-spanning paired test across Laravel 10.
- **Agent authorship detection (Aa, H3b)**: `analyse:blame` matches each introducing commit's author, committer and co-authorship trailers in memory against the documented `agent_patterns` set and persists only the Boolean and matched tool name — no author name or email is ever stored. `analyse:report` gates the H3b comparison on trace prevalence and reports the search itself where traces are too rare; `analyse:verify` reports per-repository prevalence.
- **Multiplicity control**: every p-value produced in a report run is collected once; the pre-registered primary metrics (`config('analyser.primary_metrics')`, the version-controlled pre-registration) pass through unadjusted and the exploratory family is Benjamini-Hochberg adjusted into a `multiplicity` block.
- **Sensitivity series**: `analyse:report --sensitivity` re-runs the era blocks across the four configured boundaries (Pest 1.0, Copilot GA, Laravel 10, autonomous agents) into a `sensitivity` block, re-bucketed with no re-blame.
- **Classifier validation (Appendix C)**: `analyse:sample-types` draws a reproducible seeded sample blind to classifier output; `analyse:score-types` scores the returned hand labels — Cohen's kappa with its Landis & Koch band, confusion matrix export, per-category disagreement breakdown.
- **Statistical primitives**: hand-implemented, known-answer-tested MannKendall, TheilSen, Spearman, WilcoxonSignedRank (exact two-sided p up to n = 20), and BenjaminiHochberg, sharing one Ranking tie-handling helper.
- **Attrition reporting**: blame-scope unattributed share and paired-floor exclusions exported alongside the era block.

## [v0.2.0] - 2026-07-23

### Breaking Changes
- **Mock assertions as a first-class metric category**: Replaced single assertion count with `test_assertion_count`, `mock_assertion_count`, `total_assertion_count`, and `mock_assertion_ratio` metrics. Mock assertions (interaction verifications) are classified separately from test assertions (state/output verifications). Rationale: Mock assertions are empirically distinct from test assertions with different fault-detection profiles (Zhu et al. 2025, PACMSE, doi:10.1145/3715741).
- **Updated AI Cutoff Date**: Updated default pre/post-AI cohort cutoff date to `2022-06-21`. Rationale: Aligning pre/post-AI windowing with GitHub Copilot General Availability launch rather than ChatGPT.
- **Dataset Schema Update**: Updated `test_observations` database table schema to store separate test and mock assertion counts and ratios. Output schema changes render previous pilot runs incompatible.
