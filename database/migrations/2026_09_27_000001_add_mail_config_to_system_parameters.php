<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_parameters', function (Blueprint $table) {
            // Mail driver selection
            $table->enum('mail_driver', ['smtp', '365'])->default('smtp')->after('sync_paused');
            $table->string('mail_from_address')->nullable()->after('mail_driver');
            $table->string('mail_from_name')->nullable()->after('mail_from_address');

            // SMTP Configuration
            $table->string('smtp_host')->nullable()->after('mail_from_name');
            $table->integer('smtp_port')->nullable()->after('smtp_host');
            $table->string('smtp_username')->nullable()->after('smtp_port');
            $table->text('smtp_password')->nullable()->after('smtp_username'); // Encrypted
            $table->enum('smtp_encryption', ['tls', 'ssl'])->nullable()->after('smtp_password');

            // Office 365 Configuration
            $table->string('office365_tenant_id')->nullable()->after('smtp_encryption');
            $table->string('office365_client_id')->nullable()->after('office365_tenant_id');
            $table->text('office365_client_secret')->nullable()->after('office365_client_id'); // Encrypted
            $table->json('office365_scopes')->nullable()->after('office365_client_secret');
        });
    }

    public function down(): void
    {
        Schema::table('system_parameters', function (Blueprint $table) {
            $table->dropColumn([
                'mail_driver',
                'mail_from_address',
                'mail_from_name',
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_password',
                'smtp_encryption',
                'office365_tenant_id',
                'office365_client_id',
                'office365_client_secret',
                'office365_scopes',
            ]);
        });
    }
};
