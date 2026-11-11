<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id');
            $table->string('license_key', 100)->unique();
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->enum('status', ['ACTIVE', 'EXPIRED', 'REVOKED'])->default('ACTIVE');
            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices');
            $table->index(['device_id', 'status', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
