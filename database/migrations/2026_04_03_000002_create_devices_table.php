<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('location_id');
            $table->string('device_fingerprint', 255)->unique();
            $table->string('name', 100)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('location_id')->references('id')->on('locations');
            $table->index(['location_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
