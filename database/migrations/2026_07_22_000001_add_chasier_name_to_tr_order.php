<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order', function (Blueprint $table) {
            $table->string('chasier_name')->nullable()->after('sender_name');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order', function (Blueprint $table) {
            $table->dropColumn('chasier_name');
        });
    }
};
