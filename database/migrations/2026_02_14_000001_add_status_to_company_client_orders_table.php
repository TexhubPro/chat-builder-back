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
        Schema::table('company_client_orders', function (Blueprint $table): void {
            $table->string('status', 32)
                ->default('new')
                ->after('ordered_at');
            $table->timestamp('completed_at')
                ->nullable()
                ->after('status');

            $table->index(['company_id', 'status', 'ordered_at'], 'cco_company_status_ordered_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_client_orders', function (Blueprint $table): void {
            $table->dropIndex('cco_company_status_ordered_idx');
            $table->dropColumn(['status', 'completed_at']);
        });
    }
};
