<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_branch', function (Blueprint $table) {
            $table->string('logo_header_src')->nullable()->after('printing_footer');
            $table->string('image_footer_src')->nullable()->after('logo_header_src');
        });
    }

    public function down(): void
    {
        Schema::table('mr_branch', function (Blueprint $table) {
            $table->dropColumn(['logo_header_src', 'image_footer_src']);
        });
    }
};
