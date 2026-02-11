<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assistants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('openai_assistant_id')->nullable()->unique();
            $table->string('openai_vector_store_id')->nullable();
            $table->text('instructions')->nullable();
            $table->text('restrictions')->nullable();
            $table->string('conversation_tone', 32)->default('polite');
            $table->boolean('is_active')->default(true);
            $table->boolean('enable_file_search')->default(true);
            $table->boolean('enable_file_analysis')->default(false);
            $table->boolean('enable_voice')->default(false);
            $table->boolean('enable_web_search')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistants');
    }
};
