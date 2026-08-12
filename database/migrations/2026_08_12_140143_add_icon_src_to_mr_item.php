<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_item', function (Blueprint $table) {
            $table->string('icon_src')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('mr_item', function (Blueprint $table) {
            $table->dropColumn('icon_src');
        });
    }
};
