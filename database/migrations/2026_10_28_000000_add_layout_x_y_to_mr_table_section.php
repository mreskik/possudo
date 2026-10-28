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
        Schema::table('mr_table_section', function (Blueprint $table) {
            $table->unsignedBigInteger('layout_x')->default(0)->after('layout_height');
            $table->unsignedBigInteger('layout_y')->default(0)->after('layout_x');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mr_table_section', function (Blueprint $table) {
            $table->dropColumn(['layout_x', 'layout_y']);
        });
    }
};
