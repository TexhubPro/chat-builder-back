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
        if (!Schema::hasTable('company_client_questions')) {
            return;
        }

        if (Schema::hasColumn('company_client_questions', 'company_calendar_event_id')) {
            Schema::table('company_client_questions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('company_calendar_event_id');
            });
        }

        $columnsToDrop = [];

        if (Schema::hasColumn('company_client_questions', 'sync_with_calendar')) {
            $columnsToDrop[] = 'sync_with_calendar';
        }

        if (Schema::hasColumn('company_client_questions', 'scheduled_at')) {
            $columnsToDrop[] = 'scheduled_at';
        }

        if ($columnsToDrop !== []) {
            Schema::table('company_client_questions', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('company_client_questions')) {
            return;
        }

        Schema::table('company_client_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('company_client_questions', 'company_calendar_event_id')) {
                $table->foreignId('company_calendar_event_id')
                    ->nullable()
                    ->after('assistant_id')
                    ->constrained('company_calendar_events')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('company_client_questions', 'sync_with_calendar')) {
                $table->boolean('sync_with_calendar')->default(true)->after('position');
            }

            if (!Schema::hasColumn('company_client_questions', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('sync_with_calendar');
            }
        });
    }
};
