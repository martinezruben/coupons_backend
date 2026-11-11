<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('admin_user_id')->nullable()->index();
            $table->string('admin_name', 120)->nullable();
            $table->string('action', 20)->index(); // created|updated|deleted|login|logout
            $table->string('entity_type', 60)->index();
            $table->uuid('entity_id')->nullable();
            $table->json('changes')->nullable(); // {campo: [antes, después]}
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
