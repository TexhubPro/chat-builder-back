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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_subscription_id')->nullable()->constrained('company_subscriptions')->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('number', 64)->unique();
            $table->string('status', 32)->default('draft');
            $table->char('currency', 3)->default('TJS');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('overage_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->unsignedInteger('chat_included')->default(0);
            $table->unsignedInteger('chat_used')->default(0);
            $table->unsignedInteger('chat_overage')->default(0);
            $table->decimal('unit_overage_price', 12, 2)->default(0);
            $table->timestamp('period_started_at')->nullable();
            $table->timestamp('period_ended_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'period_started_at']);
            $table->index('due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
