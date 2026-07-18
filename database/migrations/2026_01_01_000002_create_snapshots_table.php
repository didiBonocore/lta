<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha');
            $table->unsignedTinyInteger('framework_version'); // integer Laravel major
            $table->string('kind')->default('version_boundary');
            $table->timestamp('commit_date')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'framework_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
