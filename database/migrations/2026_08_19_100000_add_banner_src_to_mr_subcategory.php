<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_subcategory', function (Blueprint $table) {
            $table->string('banner_src')->nullable()->after('icon_src');
        });
    }

    public function down(): void
    {
        Schema::table('mr_subcategory', function (Blueprint $table) {
            $table->dropColumn('banner_src');
        });
    }
};
