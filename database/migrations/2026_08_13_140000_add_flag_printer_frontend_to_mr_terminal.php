<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_terminal', function (Blueprint $table) {
            $table->boolean('flag_printer_frontend')->default(false)->after('receipt_station_id');
        });
    }

    public function down(): void
    {
        Schema::table('mr_terminal', function (Blueprint $table) {
            $table->dropColumn('flag_printer_frontend');
        });
    }
};
