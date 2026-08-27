<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// description: sync dari master_item.item_description (ERP) -- ditarik APIANDORDER
// (MasterService.GetItem(), COALESCE ke '') + upsertRows() generic (SetupServices::getMasterItem()),
// gak butuh kode tambahan di situ selama nama kolomnya cocok ('description', bukan
// 'item_description' -- ngikutin konvensi mr_item yang gak pakai prefix 'item_', sama kayak
// name/code/short_name).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_item', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('mr_item', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
