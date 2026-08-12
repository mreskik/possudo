<?php

namespace App\Services;

use App\Models\BranchModel;
use Illuminate\Support\Facades\Http;

class MemberServices
{
  protected string $endpoint;

  public function __construct()
  {
    $this->endpoint = config('services.server_endpoint', '');
  }

  // CheckByPhone: live lookup ke ERP (APIANDORDER -> Postgres langsung), BUKAN baca dari cache
  // sync lokal (mr_member) -- dipakai Kiosk buat cek nomor HP yang baru diketik customer emang
  // udah kedaftar member apa belum, butuh data paling update. Token branch yang sama dipakai
  // buat auth /pos/sync/* & /pos/endday/* diteruskan lagi ke sini (middleware.BranchTokenAuth
  // di APIANDORDER validasi ulang, lihat backend/modules/apipos/member).
  public function CheckByPhone(string $phone_number): ?array
  {
    $branch = BranchModel::first();
    if (!$branch) {
      throw new \Exception('branch belum dipilih/disimpan, lakukan setup dulu');
    }

    $response = Http::withToken($branch->token)
      ->get($this->endpoint . '/pos/member/' . $branch->id . '/by-phone/' . $phone_number);

    if ($response->json('code') !== 0) {
      throw new \Exception($response->json('message'));
    }

    return $response->json('data');
  }
}
