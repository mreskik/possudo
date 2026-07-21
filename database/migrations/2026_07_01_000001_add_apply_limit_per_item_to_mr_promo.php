<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->integer('apply_limit_per_item')->default(0)->after('apply_limit_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->dropColumn('apply_limit_per_item');
        });
    }
};
