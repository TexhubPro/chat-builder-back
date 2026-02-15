<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('role')
                ->constrained('companies')
                ->nullOnDelete();
            $table->json('page_access')
                ->nullable()
                ->after('company_id');
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('page_access')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('temporary_password_sent_at')
                ->nullable()
                ->after('openai_assistant_updated_at');

            $table->index(['company_id', 'role']);
        });

        $companyPairs = DB::table('companies')
            ->select(['id', 'user_id'])
            ->get();

        foreach ($companyPairs as $pair) {
            DB::table('users')
                ->where('id', (int) $pair->user_id)
                ->update([
                    'company_id' => (int) $pair->id,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_company_id_role_index');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('page_access');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('temporary_password_sent_at');
        });
    }
};
