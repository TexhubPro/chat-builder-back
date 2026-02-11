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
        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('status', 32)->default('inactive');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedSmallInteger('billing_cycle_days')->default(30);
            $table->unsignedInteger('assistant_limit_override')->nullable();
            $table->unsignedInteger('integrations_per_channel_override')->nullable();
            $table->unsignedInteger('included_chats_override')->nullable();
            $table->decimal('overage_chat_price_override', 12, 2)->nullable();
            $table->unsignedInteger('chat_count_current_period')->default(0);
            $table->timestamp('chat_period_started_at')->nullable();
            $table->timestamp('chat_period_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('renewal_due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('company_id');
            $table->unique('user_id');
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
    }
};
