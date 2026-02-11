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
        Schema::create('company_client_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_client_id')->constrained('company_clients')->cascadeOnDelete();
            $table->foreignId('assistant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_calendar_event_id')->nullable()->constrained('company_calendar_events')->nullOnDelete();
            $table->text('description');
            $table->string('status', 32)->default('todo');
            $table->string('board_column', 32)->default('todo');
            $table->unsignedInteger('position')->default(0);
            $table->string('priority', 16)->default('normal');
            $table->boolean('sync_with_calendar')->default(true);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'board_column', 'position'], 'cct_company_board_idx');
            $table->index(['company_client_id', 'status'], 'cct_client_status_idx');
            $table->index(['company_calendar_event_id', 'sync_with_calendar'], 'cct_calendar_sync_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_client_tasks');
    }
};
