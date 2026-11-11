<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migraciones previas usaban morphs() → tokenable_id BIGINT.
 * App\Models\User usa UUID; Sanctum debe guardar tokenable_id como string (36).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE personal_access_tokens MODIFY tokenable_id VARCHAR(36) NOT NULL');
        }
    }

    public function down(): void
    {
        // No revertir a BIGINT: los IDs ya son UUID.
    }
};
