<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screening never raises: a criterion that cannot be evaluated (missing or unreadable
 * composer.json, a failed history walk) FAILS with the reason recorded here, so the
 * candidate still holds a row in the decision log instead of being silently absent from
 * both the quartile pool and the published CSV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->text('screening_notes')->nullable()->after('manual_decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('screening_notes');
        });
    }
};
