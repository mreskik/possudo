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
      throw new \Exception('branch has not been selected/saved, please complete setup first');
    }

    $response = Http::withToken($branch->token)
      ->withHeaders(BranchAuthHeaderService::build())
      ->withOptions(['verify' => config('services.http_verify_ssl')])
      ->get($this->endpoint . '/pos/member/' . $branch->id . '/by-phone/' . $phone_number);

    if ($response->json('code') !== 0) {
      throw new \Exception($response->json('message'));
    }

    return $response->json('data');
  }

  // GetDetail: detail member online (birthday, join date, note, point, tier/level loyalty,
  // last visit branch+waktu, best 5 menu, order terakhir) -- dipanggil dari halaman Member Detail
  // POS, SELALU live ke APIANDORDER (bukan dari mr_member lokal yang cuma simpan field dasar).
  // BEDA dari CheckByPhone/method lain di file ini: method ini SENGAJA pakai timeout eksplisit
  // (connectTimeout 5s, timeout 15s -- angka sama pola SetupServices.php) + catch khusus
  // ConnectionException, supaya kalau APIANDORDER/jaringan gak bisa dihubungi, pesan yang nyampe
  // ke FE jelas "POS sedang offline" -- bukan bocoran pesan cURL mentah kayak method2 lama
  // (yang gak ada penanganan khusus ini, lihat catatan di MEMBER LIST.md).
  public function GetDetail(int $memberId): ?array
  {
    $branch = BranchModel::first();
    if (!$branch) {
      throw new \Exception('branch has not been selected/saved, please complete setup first');
    }

    try {
      $response = Http::connectTimeout(5)->timeout(15)
        ->withToken($branch->token)
        ->withHeaders(BranchAuthHeaderService::build())
        ->withOptions(['verify' => config('services.http_verify_ssl')])
        ->get($this->endpoint . '/pos/member/' . $branch->id . '/detail/' . $memberId);
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
      throw new \Exception('POS sedang offline, tidak bisa mengambil data member terkini');
    }

    if ($response->json('code') !== 0) {
      throw new \Exception($response->json('message'));
    }

    return $response->json('data');
  }
}
