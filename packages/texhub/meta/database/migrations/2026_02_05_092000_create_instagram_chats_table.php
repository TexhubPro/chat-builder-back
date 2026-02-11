<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('instagram_user_id')->index();
            $table->string('receiver_id')->index();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'instagram_user_id', 'receiver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_chats');
    }
};
