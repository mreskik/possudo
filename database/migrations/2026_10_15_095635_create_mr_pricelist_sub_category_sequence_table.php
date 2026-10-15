<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // id BUKAN autoincrement -- sama kayak mr_pricelist_detail, id-nya 1:1 sama id dari
        // ERP (truncate+insert apa adanya tiap sync, bukan di-generate lokal).
        Schema::create('mr_pricelist_sub_category_sequence', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('pricelist_id');
            $table->unsignedBigInteger('sub_category_id');
            $table->integer('sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mr_pricelist_sub_category_sequence');
    }
};
