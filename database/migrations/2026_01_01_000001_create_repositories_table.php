<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->unique();   // owner/repo
            $table->string('owner');
            $table->string('name');
            $table->string('url');
            $table->string('license')->nullable();
            $table->string('primary_test_framework')->nullable(); // phpunit|pest|mixed
            $table->date('github_created_at')->nullable();
            $table->string('clone_path')->nullable();
            $table->string('head_sha')->nullable();
            $table->timestamp('cloned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
