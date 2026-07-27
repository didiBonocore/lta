# Changelog

All notable changes to the Laravel Test Pattern Analyser will be documented in this file.

## [v0.2.0] - 2026-07-23

### Breaking Changes
- **Mock assertions as a first-class metric category**: Replaced single assertion count with `test_assertion_count`, `mock_assertion_count`, `total_assertion_count`, and `mock_assertion_ratio` metrics. Mock assertions (interaction verifications) are classified separately from test assertions (state/output verifications). Rationale: Mock assertions are empirically distinct from test assertions with different fault-detection profiles (Zhu et al. 2025, PACMSE, doi:10.1145/3715741).
- **Updated AI Cutoff Date**: Updated default pre/post-AI cohort cutoff date to `2022-06-21`. Rationale: Aligning pre/post-AI windowing with GitHub Copilot General Availability launch rather than ChatGPT.
- **Dataset Schema Update**: Updated `test_observations` database table schema to store separate test and mock assertion counts and ratios. Output schema changes render previous pilot runs incompatible.
