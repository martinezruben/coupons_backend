<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE licenses SET license_key = id');
    }

    public function down(): void
    {
        //
    }
};
