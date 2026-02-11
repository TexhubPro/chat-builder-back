<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('instagram_chats')->cascadeOnDelete();
            $table->string('direction')->default('in');
            $table->string('sender_id')->nullable();
            $table->string('recipient_id')->nullable();
            $table->string('message_type')->nullable();
            $table->text('text')->nullable();
            $table->text('media_url')->nullable();
            $table->string('media_type')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_messages');
    }
};
