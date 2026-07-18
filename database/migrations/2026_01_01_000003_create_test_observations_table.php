<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (snapshot, test method). This table is the dataset artefact — the whole
 * analysis half queries it, and it is what a replicator receives. Both instruments write
 * here: Instrument A fills framework_version (via snapshot); Instrument B backfills
 * introduced_author_date / ai_window.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('test_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete(); // denormalised

            $table->string('file_path');
            $table->string('identifier');            // method name or Pest description
            $table->string('front_end');             // phpunit|pest
            $table->string('test_type');             // unit|feature|integration|unknown
            $table->string('test_type_rule')->nullable();

            $table->unsignedInteger('assertion_count')->default(0);
            $table->unsignedInteger('mock_breadth')->default(0);
            $table->unsignedInteger('max_mock_chain_depth')->default(0);
            $table->json('mock_kinds')->nullable();

            $table->unsignedInteger('size_statements')->default(0);
            $table->unsignedInteger('size_loc')->default(0);
            $table->boolean('uses_refresh_database')->default(false);
            $table->json('setup_signals')->nullable();

            // Instrument B (nullable until the blame pass runs):
            $table->string('introduced_commit_sha')->nullable();
            $table->timestamp('introduced_author_date')->nullable();
            $table->string('ai_window')->nullable();  // pre|post

            $table->timestamps();

            $table->index(['repository_id', 'test_type']);
            $table->index('ai_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_observations');
    }
};
