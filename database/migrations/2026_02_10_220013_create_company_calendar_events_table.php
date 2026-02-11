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
        Schema::create('company_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_client_id')->constrained('company_clients')->cascadeOnDelete();
            $table->foreignId('assistant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assistant_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 32)->default('scheduled');
            $table->string('location', 255)->nullable();
            $table->string('meeting_link', 2048)->nullable();
            $table->json('reminders')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'starts_at']);
            $table->index(['company_client_id', 'starts_at']);
            $table->index(['assistant_id', 'starts_at']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_calendar_events');
    }
};
