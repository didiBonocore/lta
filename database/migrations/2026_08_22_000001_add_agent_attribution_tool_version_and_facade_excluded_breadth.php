<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the three Appendix B variables the dataset could not yet express:
 *
 * - agent_authored / agent_tool (Aa) — the Boolean agent-trace indicator with the matched
 *   tool name, reduced in memory by the blame pass from commit author/committer fields and
 *   co-authorship trailers. No author name or email is ever persisted. `null` = not yet
 *   blamed; `false` = blamed, no trace — the difference between absent evidence and
 *   evidence of absence is H3b's fallback clause.
 * - tool_version (Tv) — Appendix B defines Tv as the lta revision that produced the
 *   observation, but the schema recorded the version only on report output. Stamped by
 *   analyse:extract at row-build time.
 * - mock_breadth_excluding_facades — H2a/construct validity: mock trends "are reported
 *   both including and excluding facade fakes"; the stored mock_breadth scalar cannot be
 *   re-derived at report time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_observations', function (Blueprint $table) {
            $table->boolean('agent_authored')->nullable()->after('ai_window');
            $table->string('agent_tool')->nullable()->after('agent_authored');
            $table->string('tool_version')->nullable()->after('agent_tool');
            $table->unsignedInteger('mock_breadth_excluding_facades')->default(0)->after('mock_breadth');
            $table->index('agent_authored');
        });
    }

    public function down(): void
    {
        Schema::table('test_observations', function (Blueprint $table) {
            $table->dropIndex(['agent_authored']);
            $table->dropColumn(['agent_authored', 'agent_tool', 'tool_version', 'mock_breadth_excluding_facades']);
        });
    }
};
