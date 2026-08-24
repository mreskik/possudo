<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Restruktur kolom harga/diskon/pajak tr_order_detail & tr_order_detail_package, ngikutin
// urutan hitung baru: pajak dilepas DULUAN dari harga jual (dpp), BARU diskon dipotong dari
// dpp itu (bukan dari harga jual) -- sesuai standar PPN (potongan harga mengurangi DPP).
// Lihat diskusi & DOKUMENTASI/PERHITUNGAN PAJAK INCLUSIVE & DISKON.md di posv1-vue.
//
// Rename (murni nama, makna sama): base_price->price_pos, tax_value->tax_amount,
// discount_rate->discount_percent, discount_value->discount_amount.
// Kolom `dpp` TETAP namanya, tapi MAKNANYA berubah: dulu net SETELAH diskon, sekarang net
// SEBELUM diskon.
// Kolom BARU `net_dpp`: net SETELAH diskon -- ini yang dulu disimpen di `dpp`.
// Kolom `after_discount` DIHAPUS -- perannya digantiin net_dpp (net) + tax_amount (pajak).
// Kolom `total` TETAP namanya, tapi MAKNANYA berubah: dulu qty x base_price (gross, sebelum
// diskon), sekarang qty x (net_dpp + tax_amount) -- grand total baris ini SETELAH diskon.
//
// MySQL 5.7 belum support "RENAME COLUMN" (baru MySQL 8+), jadi pakai CHANGE (perlu nulis
// ulang definisi tipe kolomnya).
return new class extends Migration
{
    private array $tables = ['tr_order_detail', 'tr_order_detail_package'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} CHANGE base_price price_pos DECIMAL(20,2) NOT NULL");
            DB::statement("ALTER TABLE {$table} CHANGE tax_value tax_amount DECIMAL(20,2) NULL DEFAULT 0");
            DB::statement("ALTER TABLE {$table} CHANGE discount_rate discount_percent DECIMAL(10,2) NULL DEFAULT 0");
            DB::statement("ALTER TABLE {$table} CHANGE discount_value discount_amount DECIMAL(20,2) NULL DEFAULT 0");
            DB::statement("ALTER TABLE {$table} ADD COLUMN net_dpp DECIMAL(20,2) NULL DEFAULT 0 AFTER dpp");
            DB::statement("ALTER TABLE {$table} DROP COLUMN after_discount");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN after_discount DECIMAL(20,2) NULL DEFAULT 0 AFTER discount_amount");
            DB::statement("ALTER TABLE {$table} DROP COLUMN net_dpp");
            DB::statement("ALTER TABLE {$table} CHANGE discount_amount discount_value DECIMAL(20,2) NULL DEFAULT 0");
            DB::statement("ALTER TABLE {$table} CHANGE discount_percent discount_rate DECIMAL(10,2) NULL DEFAULT 0");
            DB::statement("ALTER TABLE {$table} CHANGE tax_amount tax_value DECIMAL(20,2) NULL DEFAULT 0");
            DB::statement("ALTER TABLE {$table} CHANGE price_pos base_price DECIMAL(20,2) NOT NULL");
        }
    }
};
