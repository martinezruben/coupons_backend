<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->enum('type', ['PERCENT', 'AMOUNT', 'PRICE', 'BUNDLE']);
            $table->decimal('value', 12, 2);
            $table->uuid('product_id')->nullable()->index();
            $table->uuid('subfamily_id')->nullable()->index();
            $table->uuid('family_id')->nullable()->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
