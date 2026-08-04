<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The decision log (Appendix A): one row per screened candidate holding every criterion
 * outcome, the raw measured values behind them, the manual judgement with its reason, and
 * the final verdict. Screening is two-phase — the proportion criteria are pool-relative
 * (first quartile of the candidate pool, following Miranda et al.), so their outcomes stay
 * pending until analyse:screen --finalise computes the quartiles across the pool.
 *
 * Candidates are keyed by full_name and only optionally linked to a repositories row:
 * screening requires acquisition, but most candidates will never be mined, and the log must
 * outlive any pruning of the repositories table.
 *
 * repositories gains the descriptive characteristics Appendix A reports for the corpus
 * (stars, watchers, forks, archived) plus fork provenance — recorded at acquisition because
 * they are unrecoverable if a repository is deleted or archived later. None is a criterion;
 * popularity is deliberately not a quality filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->unsignedInteger('stars')->nullable()->after('github_created_at');
            $table->unsignedInteger('watchers')->nullable()->after('stars');
            $table->unsignedInteger('forks')->nullable()->after('watchers');
            $table->boolean('archived')->nullable()->after('forks');
            $table->boolean('is_fork')->nullable()->after('archived');
            $table->string('fork_parent')->nullable()->after('is_fork');
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->unique();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();

            // Criterion outcomes: pass | fail | pending.
            $table->string('dependency_ok')->default('pending');   // laravel/framework direct dependency (illuminate/support alone fails)
            $table->string('majors_ok')->default('pending');       // >= 3 integer majors on the first-parent walk
            $table->string('suite_ok')->default('pending');        // >= 1 file routes to a front end at HEAD
            $table->string('cloneable_ok')->default('pending');    // full history acquired
            $table->string('package_ok')->default('pending');      // not the framework core or a package
            $table->string('fork_ok')->default('pending');         // not a fork without independent history
            $table->string('file_proportion_ok')->default('pending'); // phase 2: >= pool Q1
            $table->string('loc_proportion_ok')->default('pending');  // phase 2: >= pool Q1
            $table->string('manual_ok')->default('pending');       // tutorial/coursework/demo judgement

            // Raw measured values — the log must show 3.1% against a 4.7% quartile, not
            // a bare fail.
            $table->string('framework_constraint')->nullable();
            $table->string('composer_type')->nullable();
            $table->unsignedTinyInteger('majors_count')->nullable();
            $table->unsignedInteger('suite_routed_files')->nullable();
            $table->unsignedInteger('fork_ahead_by')->nullable();
            $table->unsignedInteger('test_file_count')->nullable();
            $table->unsignedInteger('php_file_count')->nullable();
            $table->float('test_file_proportion')->nullable();
            $table->unsignedInteger('test_line_count')->nullable();
            $table->unsignedInteger('php_line_count')->nullable();
            $table->float('test_loc_proportion')->nullable();

            // Shared-history detection (§3.6): both halves of a detected pair are flagged
            // for the manual gate; neither is auto-excluded.
            $table->string('root_commit_sha')->nullable();
            $table->string('shared_history_with')->nullable();
            $table->string('shared_divergence_sha')->nullable();

            $table->string('manual_decision')->nullable();         // include | exclude
            $table->string('manual_reason')->nullable();
            $table->timestamp('manual_decided_at')->nullable();

            $table->string('verdict')->default('pending');         // included | excluded | pending
            $table->timestamp('verdict_computed_at')->nullable();
            $table->timestamp('screened_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['stars', 'watchers', 'forks', 'archived', 'is_fork', 'fork_parent']);
        });
    }
};
