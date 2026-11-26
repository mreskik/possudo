<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_promo dapat kolom `type_bill_amount` -- nominal diskon promo type 'bill' (Bill Discount), 1:1 dengan
// master_promo.type_bill_amount di ERP (sudocore2 migration 357). Kolom `type` di mr_promo string biasa
// (tanpa constraint), jadi nilai 'bill' sudah bisa tersimpan tanpa perubahan lain.
// Default 0 supaya POS yang sync dari ERP lama (belum kirim field ini) tetap jalan.
// WAJIB di-migrate di POS SEBELUM APIANDORDER mulai mengirim field `type_bill_amount`: model promo memakai
// $guarded = [] dan replaceRows() menyisipkan semua key apa adanya, jadi kolom yang belum ada = sync promo error.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->decimal('type_bill_amount', 20, 2)->nullable()->default(0)->after('type_rupiah_amount');
        });
    }

    public function down(): void
    {
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->dropColumn('type_bill_amount');
        });
    }
};
