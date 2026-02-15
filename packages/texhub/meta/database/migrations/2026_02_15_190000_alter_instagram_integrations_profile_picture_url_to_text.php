<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `instagram_integrations` MODIFY `profile_picture_url` TEXT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "instagram_integrations" ALTER COLUMN "profile_picture_url" TYPE TEXT');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `instagram_integrations` MODIFY `profile_picture_url` VARCHAR(255) NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "instagram_integrations" ALTER COLUMN "profile_picture_url" TYPE VARCHAR(255)');
        }
    }
};

