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
}