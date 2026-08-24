<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BranchModel;
use App\Models\CategoryModel;
use App\Models\MasterBranchVisitPurposeModel;
use App\Models\MasterMemberTypeModel;
use App\Models\MasterPaymentMethodModel;
use App\Models\MasterVisitPurposeModel;
use App\Models\StationModel;
use App\Models\SubCategoryModel;
use App\Models\TableModel;
use App\Models\TableSectionModel;
use App\Models\TerminalModel;
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

    public function GetMemberList()
    {
        try {
            $data = DB::select("
                SELECT
                    m.id,
                    m.member_type_id,
                    mt.name as member_type_name,
                    m.code,
                    m.name,
                    m.contact_name,
                    m.email,
                    m.phone_number,
                    m.is_active
                FROM mr_member m
                LEFT JOIN mr_member_type mt ON mt.id = m.member_type_id
                ORDER BY m.name ASC
            ");

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

    public function GetMemberTypeList()
    {
        try {
            $data = MasterMemberTypeModel::where('is_active', true)->get();

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

    public function GetPromoList(Request $request)
    {
        $visit_purpose_id = $request->route("visit_purpose_id");

        // member_type_id is optional/nullable: order page doesn't know the member yet (member is
        // only picked at payment), so this is passed null from there. Passing null is intentional,
        // not a bug — "type_member_id = NULL" never matches in SQL, so it naturally falls back to
        // only flag_all_type_members=true promos, exactly as it should when no member is known.
        // Payment page (once wired) can call this again with the actual member_type_id to also
        // surface member-restricted promos.
        $member_type_id = $request->query("member_type_id");

        $day_names = ['minggu', 'senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu'];
        $today_day = $day_names[now()->dayOfWeek];
        $now_time = now()->format('H:i:s');

        try {
            $data = DB::select("
                SELECT
                    mp.id,
                    mp.name,
                    mp.code,
                    mp.type,
                    mp.type_rupiah_amount,
                    mp.type_percent_rate,
                    mp.type_percent_limit_amount,
                    mp.type_freeitem_item_id,
                    mp.promo_for,
                    mp.min_buy_amount,
                    mp.flag_include_package,
                    mp.apply_limit_per_day,
                    mp.apply_limit_per_item,
                    mp.period_start,
                    mp.period_end
                FROM mr_promo mp
                WHERE mp.is_active = true
                    AND (mp.period_start IS NULL OR mp.period_start <= CURDATE())
                    AND (mp.period_end IS NULL OR mp.period_end >= CURDATE())
                    AND (
                        mp.flag_all_visit_purposes = true
                        OR EXISTS (
                            SELECT 1 FROM mr_promo_visit_purposes mpvp
                            WHERE mpvp.promo_id = mp.id AND mpvp.visit_purpose_id = ?
                        )
                    )
                    AND (
                        mp.flag_all_type_members = true
                        OR EXISTS (
                            SELECT 1 FROM mr_promo_type_members mptm
                            WHERE mptm.promo_id = mp.id AND mptm.type_member_id = ?
                        )
                    )
                    AND (
                        mp.flag_all_days = true
                        OR EXISTS (
                            SELECT 1 FROM mr_promo_days mpd
                            WHERE mpd.promo_id = mp.id AND mpd.day = ?
                        )
                    )
                    AND (
                        mp.flag_all_times = true
                        OR EXISTS (
                            SELECT 1 FROM mr_promo_times mpt
                            WHERE mpt.promo_id = mp.id AND ? BETWEEN mpt.time_start AND mpt.time_end
                        )
                    )
                    AND (
                        mp.flag_apply_to_all = true
                        OR EXISTS (
                            SELECT 1 FROM mr_promo_apply_to mpat
                            WHERE mpat.promo_id = mp.id AND mpat.apply_to = 'pos'
                        )
                    )
                ORDER BY mp.name ASC
            ", [$visit_purpose_id, $member_type_id, $today_day, $now_time]);

            $this->AttachPromoTargetIds($data);
            $this->AttachUsedToday($data);

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

    // hitung berapa kali tiap promo sudah dipakai hari ini (distinct order),
    // untuk enforce apply_limit_per_day di frontend
    private function AttachUsedToday(array $promoList)
    {
        $promoIds = array_column($promoList, 'id');
        if (empty($promoIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($promoIds), '?'));

        $usageRows = DB::select("
            SELECT trod.promo_id, COUNT(DISTINCT trod.order_number) as used_today
            FROM tr_order_detail trod
            JOIN tr_order tro ON tro.order_number = trod.order_number
            WHERE trod.promo_id IN ($placeholders)
                AND DATE(tro.order_in) = CURDATE()
                AND trod.cancel_at IS NULL
                AND tro.status IN ('pending', 'hold', 'paid')
            GROUP BY trod.promo_id
        ", $promoIds);

        $usageMap = [];
        foreach ($usageRows as $row) {
            $usageMap[$row->promo_id] = (int) $row->used_today;
        }

        foreach ($promoList as $promo) {
            $promo->used_today = $usageMap[$promo->id] ?? 0;
        }
    }

    public function GetTerminalList()
    {
        try {
            $data = TerminalModel::where('is_active', true)->get();
            return response()->json([
                'code' => 0,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // GetBannerImageCustomerDisplay: daftar gambar banner/slideshow buat customer display kasir
    // (channel cd_pos) -- mirror KioskController::GetBannerImageKiosk(), baca mr_image_customer_display
    // (2026-08-24, plug point buat DisplayCustomerPage.vue yang sebelumnya hardcode 2 gambar
    // statis). banner_src udah path lokal POS (didownload pas pull, lihat
    // SetupServices::getMasterImageCustomerDisplay()).
    public function GetBannerImageCustomerDisplay()
    {
        try {
            $data = DB::select("SELECT
                    micd.name,
                    micd.banner_src,
                    micd.sequence
                    FROM mr_image_customer_display micd
                    JOIN mr_image mi ON mi.id = micd.master_image_id
                    WHERE mi.is_active = 1
                    ORDER BY micd.sequence ASC");

            return response()->json([
                'code' => 0,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // promo_for menentukan tabel target mana yang dipakai (category/subcategory/item).
    // targetIds dipakai frontend buat cocokkan cart line mana yang berhak dapat diskon promo ini.
    private function AttachPromoTargetIds(array $promoList)
    {
        $promoIds = array_column($promoList, 'id');
        if (empty($promoIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($promoIds), '?'));

        $categoryTargets = DB::select("SELECT promo_id, category_id FROM mr_promo_categories WHERE promo_id IN ($placeholders)", $promoIds);
        $subCategoryTargets = DB::select("SELECT promo_id, sub_category_id FROM mr_promo_sub_categories WHERE promo_id IN ($placeholders)", $promoIds);
        $itemTargets = DB::select("SELECT promo_id, item_id FROM mr_promo_items WHERE promo_id IN ($placeholders)", $promoIds);

        foreach ($promoList as $promo) {
            $promo->targetIds = match ($promo->promo_for) {
                'category' => array_values(array_column(
                    array_filter($categoryTargets, fn($t) => $t->promo_id == $promo->id),
                    'category_id'
                )),
                'subcategory' => array_values(array_column(
                    array_filter($subCategoryTargets, fn($t) => $t->promo_id == $promo->id),
                    'sub_category_id'
                )),
                'item' => array_values(array_column(
                    array_filter($itemTargets, fn($t) => $t->promo_id == $promo->id),
                    'item_id'
                )),
                default => [],
            };
        }
    }
}
