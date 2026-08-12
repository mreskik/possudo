<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mr_image', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });

        Schema::create('mr_image_list', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('master_image_id');
            $table->text('image_src');
            $table->unsignedBigInteger('sequence')->default(0);
        });

        Schema::create('mr_image_list_apply_for', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('master_image_list_id');
            $table->string('apply_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_image_list_apply_for');
        Schema::dropIfExists('mr_image_list');
        Schema::dropIfExists('mr_image');
    }
};
