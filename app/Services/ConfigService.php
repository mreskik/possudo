<?php
namespace App\Services;

use App\Models\SetupConfigModel;
use Illuminate\Support\Facades\Log;

class ConfigService {


  public static function ChangeStatusInstall(int $status){
    try{
      if ($status == 1){
        SetupConfigModel::where('id' , 1)->update([
          'flag_install_status' => true,
        ]);
      }elseif($status == 0){
        SetupConfigModel::where('id' , 1)->update([
          'flag_install_status' => false,
        ]);
      }
        return 'success';

    }catch(\Throwable $e){
      Log::info($e->getMessage());
      return $e->getMessage();
    }
  }

  // GetInstallStatus -- baca balik flag_install_status (id=1, baris ini SELALU ada sejak
  // migration 2026_05_20_035027_setup.php, di-seed false). Ditambahkan 2026-10-27 -- kolom ini
  // sebelumnya cuma ditulis (ChangeStatusInstall), gak pernah dibaca ulang dari endpoint manapun.
  public static function GetInstallStatus(): bool {
    $config = SetupConfigModel::where('id', 1)->first();
    return (bool) ($config?->flag_install_status ?? false);
  }
}