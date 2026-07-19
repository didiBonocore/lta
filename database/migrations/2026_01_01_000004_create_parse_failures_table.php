<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per test file the extraction core could not parse at a given snapshot. Parse
 * errors never abort a run; they are recorded here so the dissertation's
 * threats-to-validity section can quote an exact, auditable failure rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parse_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('commit_sha');
            $table->string('message');
            $table->timestamps();

            $table->index(['repository_id', 'snapshot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_failures');
    }
};
