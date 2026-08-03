<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trunk-constrained snapshots and unroutable-file auditability:
 *
 * - repositories.default_branch — the branch HEAD pointed at when the clone was acquired.
 *   Instrument A's --first-parent walk is only meaningful relative to a trunk; this records
 *   which one, and cannot be reconstructed later if the project renames its default branch.
 * - snapshots.first_parent_index — the representative's position in the first-parent walk of
 *   composer.json-touching commits, so major ordering can be checked against trunk position
 *   rather than author date (merge dates need not be monotonic with authorship).
 * - unroutable_files — one row per test-suite file that parses but no front end owns,
 *   mirroring parse_failures. Appendix C promises auditability for excluded files; these
 *   were previously visible only in a non-persisted console counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('default_branch')->nullable()->after('head_sha');
        });

        Schema::table('snapshots', function (Blueprint $table) {
            $table->unsignedInteger('first_parent_index')->nullable()->after('commit_date');
        });

        Schema::create('unroutable_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('commit_sha');
            // Base class from the extends clause of the file's first class declaration,
            // resolved through file-level use statements where possible (raw name where not);
            // a sentinel string where the file has no class and no top-level it()/test() call.
            $table->string('base_class')->nullable();
            $table->timestamps();

            $table->index(['repository_id', 'snapshot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unroutable_files');

        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropColumn('first_parent_index');
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('default_branch');
        });
    }
};
