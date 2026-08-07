<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_terminal', function (Blueprint $table) {
            $table->unsignedBigInteger('table_section_id')->nullable()->after('is_used');
            $table->unsignedBigInteger('receipt_station_id')->nullable()->after('table_section_id');
        });
    }

    public function down(): void
    {
        Schema::table('mr_terminal', function (Blueprint $table) {
            $table->dropColumn(['table_section_id', 'receipt_station_id']);
        });
    }
};
