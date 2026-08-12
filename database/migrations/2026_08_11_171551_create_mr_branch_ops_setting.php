<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mr_branch_ops_setting', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('day');
            $table->string('status');
            $table->time('open_time')->nullable();
            $table->time('closed_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_branch_ops_setting');
    }
};
