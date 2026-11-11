<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('location_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->timestamp('last_pull_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('device_id')->references('id')->on('devices');
            $table->unique(['location_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
    }
};
