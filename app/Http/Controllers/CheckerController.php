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

            PrintServices::PrintBill("NOTB202605301457");
            // PrintServices::PrintPayment(14, "TOTB1779759269");
            return response("");
        } catch (\Throwable $e) {
            Log::info($e);
            return "";
        }
    }
}
