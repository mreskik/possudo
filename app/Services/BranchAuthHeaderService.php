<?php

namespace App\Services;

// Header metadata device yang disisipkan di SEMUA request ber-token ke APIANDORDER
// (sync/push/heartbeat/mobile-order/member/dll) -- dibaca middleware.BranchTokenAuth di sana,
// disimpan (throttled, cuma kalau beda dari cache terakhir) ke master_branch_pos_session buat
// keperluan monitoring. Lihat DOKUMENTASI API/MASTER/MASTER BRANCH POS SESSION.md (sudocore2).
class BranchAuthHeaderService
{
  public static function build(): array
  {
    return [
      'X-App-Version' => (string) env('APP_VERSION', ''),
      'X-OS-Version' => PHP_OS_FAMILY . ' ' . php_uname('r'),
    ];
  }
}
