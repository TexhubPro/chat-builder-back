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
        Schema::create('company_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'phone']);
            $table->unique(['company_id', 'email']);
            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_clients');
    }
};
