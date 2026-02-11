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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender_type', 32)->default('customer');
            $table->string('direction', 16)->default('inbound');
            $table->string('status', 32)->default('received');
            $table->string('channel_message_id')->nullable();
            $table->string('message_type', 32)->default('text');
            $table->longText('text')->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('media_mime_type', 191)->nullable();
            $table->unsignedBigInteger('media_size')->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->json('attachments')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'channel_message_id']);
            $table->index(['chat_id', 'created_at']);
            $table->index(['company_id', 'sender_type', 'message_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
