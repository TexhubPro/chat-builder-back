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
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assistant_channel_id')->nullable()->constrained('assistant_channels')->nullOnDelete();
            $table->string('channel', 32);
            $table->string('channel_chat_id')->nullable();
            $table->string('channel_user_id')->nullable();
            $table->string('name', 160)->nullable();
            $table->string('avatar', 2048)->nullable();
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('status', 32)->default('open');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'channel', 'channel_chat_id']);
            $table->index(['user_id', 'company_id', 'status']);
            $table->index(['company_id', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
