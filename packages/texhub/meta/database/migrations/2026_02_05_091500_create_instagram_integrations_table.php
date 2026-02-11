<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('instagram_user_id')->index();
            $table->string('username')->nullable();
            $table->string('receiver_id')->nullable();
            $table->longText('access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->string('profile_picture_url')->nullable();
            $table->string('avatar_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'instagram_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_integrations');
    }
};
