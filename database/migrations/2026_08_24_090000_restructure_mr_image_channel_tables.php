<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_image_list + mr_image_list_apply_for diganti 2 tabel eksplisit per-channel, ngikutin
// restrukturisasi master_image di ERP (sudocore2 migration 112+113) -- 1 gambar cuma nempel
// ke 1 channel (implisit dari tabel mana dia disimpen), gak ada lagi multi-channel per row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mr_image_list_apply_for');
        Schema::dropIfExists('mr_image_list');

        Schema::create('mr_image_customer_display', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('master_image_id');
            $table->string('name');
            $table->text('image_src');
            $table->unsignedBigInteger('sequence')->default(0);
        });

        Schema::create('mr_image_kiosk', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('master_image_id');
            $table->string('name');
            $table->text('image_src');
            $table->unsignedBigInteger('sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_image_kiosk');
        Schema::dropIfExists('mr_image_customer_display');

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
};
