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
        Schema::create('assistant_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('name', 120)->nullable();
            $table->string('external_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['assistant_id', 'channel']);
            $table->index(['company_id', 'channel', 'is_active']);
            $table->index(['user_id', 'company_id']);
            $table->index('external_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_channels');
    }
};
