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
        Schema::table('mr_setting', function (Blueprint $table) {
            $table->boolean('use_virtual_keyboard')->default(false)->after('customer_display_top');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mr_setting', function (Blueprint $table) {
            $table->dropColumn('use_virtual_keyboard');
        });
    }
};
