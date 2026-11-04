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
            $table->string('print_checker_on', 20)->default('save_order')->after('print_category_setting_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mr_table_section', function (Blueprint $table) {
            $table->dropColumn('print_checker_on');
        });
    }
};
