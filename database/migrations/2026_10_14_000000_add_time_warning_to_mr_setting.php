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
            $table->integer('first_time_warning')->default(0)->after('use_virtual_keyboard');
            $table->integer('second_time_warning')->default(0)->after('first_time_warning');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mr_setting', function (Blueprint $table) {
            $table->dropColumn(['first_time_warning', 'second_time_warning']);
        });
    }
};
