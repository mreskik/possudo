<?php

namespace App\Http\Controllers;

use App\Services\PrintServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckerController extends Controller
{
    //

    public function cek()
    {
        try {

            // PrintServices::PrintMainChecker(11, "TOTB1779702969");
            // PrintServices::PrintTableChecker2(14, "TOTB1779865370", true);
            // PrintServices::PrintMainChecker2(14, "TOTB1779717210");
            // PrintServices::PrintPriparationStation(14, "TOTB1779865370", true);

            // kode uji manual -- station_id hardcode buat testing, bukan dari session.
            PrintServices::PrintBill("NOTB202605301457", 1);
            // PrintServices::PrintPayment("TOTB1779759269", 1);
            return response("");
        } catch (\Throwable $e) {
            Log::info($e);
            return "";
        }
    }
}
