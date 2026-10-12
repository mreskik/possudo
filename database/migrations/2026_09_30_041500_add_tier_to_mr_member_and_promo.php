<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Barrier tier promo (2026-09-30) -- selaras sama master_promo.flag_all_tiers/master_promo_tier
// ERP (migration sudocore2 233) dan tier member (master_member_tier_setting_detail, migration
// sudocore2 108). POS sebelumnya GAK PUNYA konsep tier member sama sekali -- kolom
// mr_member.tier_level BARU, disinkronkan via getMemberList sync pull, DIHITUNG OTOMATIS oleh
// job membertierevaluation di sudocore2 (BUKAN input manual di POS).
//
// mr_promo_tier mirror mr_promo_type_members -- tier_level (BUKAN tier_id), konsisten sama
// nama kolom yang dipakai di ERP/sudomobile, tier gak punya PK surrogate `id`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_member', function (Blueprint $table) {
            $table->integer('tier_level')->default(1)->after('member_type_id');
        });

        Schema::table('mr_promo', function (Blueprint $table) {
            $table->boolean('flag_all_tiers')->default(true)->after('flag_all_type_members');
        });

        Schema::create('mr_promo_tier', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('promo_id');
            $table->integer('tier_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_promo_tier');
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->dropColumn('flag_all_tiers');
        });
        Schema::table('mr_member', function (Blueprint $table) {
            $table->dropColumn('tier_level');
        });
    }
};
