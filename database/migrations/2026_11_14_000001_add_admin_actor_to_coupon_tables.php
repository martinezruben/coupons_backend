<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** El panel (admin_users, ID entero) también restablece saldos y revierte cobros: se registra quién. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['coupon_audit_logs', 'coupon_transactions'] as $table) {
            if (! Schema::hasColumn($table, 'admin_user_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('admin_user_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['coupon_audit_logs', 'coupon_transactions'] as $table) {
            if (Schema::hasColumn($table, 'admin_user_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('admin_user_id'));
            }
        }
    }
};
