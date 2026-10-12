<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_logs') && Schema::hasColumn('request_logs', 'created_at'))
        {
            if (!$this->eventExists('cleanup_request_logs'))
            {
                DB::unprepared("
                    CREATE EVENT cleanup_request_logs
                    ON SCHEDULE EVERY 1 DAY
                    DO
                        DELETE FROM request_logs
                        WHERE created_at < NOW() - INTERVAL 7 DAY
                ");
            }
        }

        if (Schema::hasTable('error_logs') && Schema::hasColumn('error_logs', 'created_at'))
        {
            if (!$this->eventExists('cleanup_error_logs'))
            {
                DB::unprepared("
                    CREATE EVENT cleanup_error_logs
                    ON SCHEDULE EVERY 1 DAY
                    DO
                        DELETE FROM error_logs
                        WHERE created_at < NOW() - INTERVAL 30 DAY
                ");
            }
        }

        if (Schema::hasTable('activity_logs') && Schema::hasColumn('activity_logs', 'created_at'))
        {
            if (!$this->eventExists('cleanup_activity_logs'))
            {
                DB::unprepared("
                    CREATE EVENT cleanup_activity_logs
                    ON SCHEDULE EVERY 1 DAY
                    DO
                        DELETE FROM activity_logs
                        WHERE created_at < NOW() - INTERVAL 7 DAY
                ");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared("DROP EVENT IF EXISTS cleanup_request_logs");
        DB::unprepared("DROP EVENT IF EXISTS cleanup_error_logs");
        DB::unprepared("DROP EVENT IF EXISTS cleanup_activity_logs");
    }

    private function eventExists(string $eventName): bool
    {
        return DB::table('information_schema.EVENTS')
            ->where('EVENT_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_NAME', $eventName)
            ->exists();
    }
};