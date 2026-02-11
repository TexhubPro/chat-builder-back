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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_enterprise')->default(false);
            $table->unsignedSmallInteger('billing_period_days')->default(30);
            $table->char('currency', 3)->default('TJS');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('included_chats')->default(0);
            $table->decimal('overage_chat_price', 12, 2)->default(0);
            $table->unsignedInteger('assistant_limit')->default(0);
            $table->unsignedInteger('integrations_per_channel_limit')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('features')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_public', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
