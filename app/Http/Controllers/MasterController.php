<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BranchModel;
use App\Models\CategoryModel;
use App\Models\MasterBranchVisitPurposeModel;
use App\Models\MasterPaymentMethodModel;
use App\Models\MasterVisitPurposeModel;
use App\Models\StationModel;
use App\Models\SubCategoryModel;
use App\Models\TableModel;
use App\Models\TableSectionModel;
use App\Services\MenuServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MasterController extends Controller
{
    //
    public function getTableSection(Request $request)
    {
        try {
            $data = TableSectionModel::where('is_active', true)->get();
            return response()->json([
                'code' => 0,
                'data' => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 0,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getTableSectionTable(Request $request)
    {
        try {
            $data = TableModel::get();
            $afterGrouping = $data->groupBy('table_section_id');

            return response()->json([
                'code' => 0,
                'data' => $afterGrouping
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 0,
                'message' => $e->getMessage()
            ]);
        }
    }



    public function getVisitPurpose(Request $request)
    {
        try {
            $data = DB::select("SELECT
                    vp.id,
                    vp.name
                    FROM mr_branch_visit_purpose bvp
                    JOIN mr_visit_purpose vp on vp.id = bvp.visit_purpose_id");
            return response()->json([
                'code' => 0,
                'data' => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 0,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function GetMasterMenuList(Request $request)
    {
        try {
            $data = MenuServices::GetMasterMenuList();
            return response()->json([
                'code' => 0,
                'data' => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 0,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function GetPaymentMethod(Request $request)
    {

        $visit_purpose_id = $request->route("visit_purpose_id");

        try {
            $data = DB::select("
            SELECT
                mpm.* 
            FROM
                mr_payment_method_visit_purposes mpmvp
                JOIN mr_payment_method mpm ON mpm.id = mpmvp.payment_method_id 
            WHERE
                mpmvp.visit_purpose_id = ?
            ", [$visit_purpose_id]);
            return response()->json([
                'code' => 0,
                'data' => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 0,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function GetBranchDetail()
    {
        $data = BranchModel::first();

        if ($data) {

            return response()->json([
                'code' => 0,
                'data' => $data
            ]);
        } else {
            return response()->json([
                'code' => 100,
                'message' => "branchDetail gak muncul!"
            ]);
        }
    }
    public function GetStationList()
    {
        $data = StationModel::get();
        if ($data) {

            return response()->json([
                'code' => 0,
                'data' => $data
            ]);
        } else {
            return response()->json([
                'code' => 100,
                'message' => "station gak muncul!"
            ]);
        }
    }

    public function GetCategoryList()
    {
        try {

            $data = CategoryModel::get();

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    public function GetSubCategoryList()
    {
        try {

            $data = SubCategoryModel::get();

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }
}
