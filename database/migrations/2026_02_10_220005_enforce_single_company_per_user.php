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
        $duplicateUserIds = DB::table('companies')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds as $userId) {
            $keepCompanyId = DB::table('companies')
                ->where('user_id', $userId)
                ->max('id');

            DB::table('companies')
                ->where('user_id', $userId)
                ->where('id', '!=', $keepCompanyId)
                ->delete();
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_user_id_slug_unique');
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_user_id_unique');
            $table->unique(['user_id', 'slug']);
        });
    }
};
