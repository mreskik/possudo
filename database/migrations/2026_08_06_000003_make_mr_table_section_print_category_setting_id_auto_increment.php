<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Sebelumnya kolom id di sini diisi manual dari data server (APIANDORDER), yang bisa
// bentrok (duplicate PK) kalau ada table section yang link print_category_setting ke
// table section lain (server balikin id sumber yang sama untuk beberapa table_section_id
// berbeda). Kolom id jadi auto-increment lokal supaya insert sync gak pernah bentrok lagi.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mr_table_section_print_category_setting MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mr_table_section_print_category_setting MODIFY id BIGINT UNSIGNED NOT NULL');
    }
};
