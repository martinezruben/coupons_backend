<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('subfamilies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained('families')->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('subfamily_id')->nullable()->after('name')->constrained('subfamilies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['subfamily_id']);
        });

        Schema::dropIfExists('subfamilies');
        Schema::dropIfExists('families');
    }
};
