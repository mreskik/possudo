<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_subcategory', function (Blueprint $table) {
            $table->string('icon_src')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('mr_subcategory', function (Blueprint $table) {
            $table->dropColumn('icon_src');
        });
    }
};
