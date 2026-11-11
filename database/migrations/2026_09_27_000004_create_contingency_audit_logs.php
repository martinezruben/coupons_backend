<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingency_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('location_id');
            $table->string('location_name');
            $table->enum('event', ['activated', 'resolved', 'reminder_sent']);
            $table->text('sent_to')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->index('location_id');
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contingency_audit_logs');
    }
};
