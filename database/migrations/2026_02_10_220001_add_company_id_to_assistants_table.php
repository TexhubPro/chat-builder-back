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
        Schema::table('assistants', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('user_id')
                ->constrained('companies')
                ->nullOnDelete();

            $table->index(['user_id', 'company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assistants', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'company_id']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
