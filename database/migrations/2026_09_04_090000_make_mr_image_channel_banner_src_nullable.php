<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// mr_image_kiosk/mr_image_customer_display.banner_src kebawa NOT NULL dari migration
// 2026_08_24_090000 (image_src, gak dikasih ->nullable() pas dibikin) -- beda dari kolom
// gambar hasil download lain di app ini (mr_item.image/icon_src, mr_branch.logo_header_src,
// mr_subcategory.icon_src/banner_src, semuanya nullable), padahal sumber datanya sama-sama
// lewat SetupServices::downloadImage() yang BISA balikin null (gagal download/network hiccup,
// row baru pertama sync jadi gak ada fallback lokal). Ketauan lewat error live
// "SQLSTATE: Integrity constraint violation ... banner_src cannot be null" pas getMasterKiosk
// sync (2026-09-04) -- 1 gambar gagal download bikin SELURUH batch upsert mr_image_kiosk gagal
// (upsert 1 statement buat banyak baris), bukan cuma baris yang gagal doang.
//
// Raw SQL (bukan Schema::table(...)->nullable()->change()) -- ngindarin depend ke
// doctrine/dbal yang gak ada di composer.json project ini.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mr_image_customer_display MODIFY banner_src TEXT NULL');
        DB::statement('ALTER TABLE mr_image_kiosk MODIFY banner_src TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mr_image_customer_display MODIFY banner_src TEXT NOT NULL');
        DB::statement('ALTER TABLE mr_image_kiosk MODIFY banner_src TEXT NOT NULL');
    }
};
