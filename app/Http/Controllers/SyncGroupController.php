<?php

namespace App\Http\Controllers;

use App\Services\PushDataServices;
use App\Services\SetupServices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SyncGroupController: endpoint BARU (2026-08-31) yang ngerangkum banyak sync_pull individual
// (routes/api.php, prefix `sync_pull`) jadi 1 kelompok per kategori -- dipakai UI baru di
// SettingPage.vue (tab checkbox per kategori + tombol "Sync"), biar user gak perlu manggil
// belasan endpoint satu-satu. Route/controller LAMA (SyncController) TETAP APA ADANYA, gak
// dihapus/diubah sama sekali -- controller ini murni nambah lapisan orkestrasi baru di
// atasnya, reuse App\Services\SetupServices/PushDataServices yang sama persis, gak duplikasi
// logic sync-nya sendiri.
//
// Pengelompokan 8 kategori (disepakati 2026-08-31, dari mockup checkbox yang udah ada di
// SettingPage.vue): Table, Promotion, Member, User, Menu, Branch Setting, Master Setting -- 7
// di atas semuanya PULL dari ERP. "Sales" beda sendiri, gak ada endpoint pull yang cocok (data
// sales/shift arahnya PUSH ke ERP, bukan ditarik) -- endpoint-nya manggil PushDataServices
// (reuse 6 fungsi yang SAMA dipakai job sync:push), bukan SetupServices.
class SyncGroupController extends Controller
{
    protected SetupServices $setupservices;

    public function __construct()
    {
        $this->setupservices = new SetupServices();
    }

    private function currentBranch(): ?object
    {
        return DB::table('mr_branch')->first();
    }

    private function noBranchResponse()
    {
        return response()->json(['code' => 100, 'message' => 'branch belum dipilih/disimpan, lakukan setup dulu']);
    }

    // runSteps: jalanin tiap step SATU-SATU, try/catch PER STEP -- 1 gagal gak nahan step
    // lain, sama semangat "1 kegagalan lokal gak boleh ngebunuh keseluruhan proses" yang
    // dipakai di background job/PaymentGatewayServices di file lain.
    private function runSteps(array $steps): array
    {
        $results = [];
        foreach ($steps as $key => $callable) {
            try {
                $response = $callable();
                $results[$key] = [
                    'code' => $response->json('code'),
                    'message' => $response->json('message'),
                ];
            } catch (\Throwable $e) {
                Log::channel('jobs')->error("SyncGroupController [{$key}]: {$e->getMessage()}");
                $results[$key] = ['code' => 100, 'message' => $e->getMessage()];
            }
        }
        return $results;
    }

    private function respondGroup(array $results)
    {
        $overallCode = collect($results)->every(fn($r) => ($r['code'] ?? 100) === 0) ? 0 : 100;
        return response()->json(['code' => $overallCode, 'data' => $results]);
    }

    public function syncTable()
    {
        $branch = $this->currentBranch();
        if (!$branch) {
            return $this->noBranchResponse();
        }

        return $this->respondGroup($this->runSteps([
            // get_tablesection_list versi lama (SyncController) diam-diam ikut manggil
            // getTableSectionPrintCategorySetting() juga -- disamain di sini biar hasilnya
            // setara, bukan cuma sebagian dari yang biasanya kepanggil.
            'get_tablesection_list' => function () use ($branch) {
                $this->setupservices->getTableSectionPrintCategorySetting('', '', $branch->id, $branch->token);
                return $this->setupservices->getTableSectionList('', '', $branch->id, $branch->token);
            },
            'get_table' => fn() => $this->setupservices->getTable('', '', $branch->id, $branch->token),
        ]));
    }

    public function syncPromotion()
    {
        $branch = $this->currentBranch();
        if (!$branch) {
            return $this->noBranchResponse();
        }

        return $this->respondGroup($this->runSteps([
            'get_promo_list' => fn() => $this->setupservices->getPromoList('', '', $branch->id, $branch->token),
            'get_promo_branch' => fn() => $this->setupservices->getPromoBranch('', '', $branch->id, $branch->token),
            'get_promo_visit_purpose' => fn() => $this->setupservices->getPromoVisitPurpose('', '', $branch->id, $branch->token),
            'get_promo_type_member' => fn() => $this->setupservices->getPromoTypeMember('', '', $branch->id, $branch->token),
            'get_promo_category' => fn() => $this->setupservices->getPromoCategory('', '', $branch->id, $branch->token),
            'get_promo_sub_category' => fn() => $this->setupservices->getPromoSubCategory('', '', $branch->id, $branch->token),
            'get_promo_item' => fn() => $this->setupservices->getPromoItem('', '', $branch->id, $branch->token),
            'get_promo_day' => fn() => $this->setupservices->getPromoDay('', '', $branch->id, $branch->token),
            'get_promo_time' => fn() => $this->setupservices->getPromoTime('', '', $branch->id, $branch->token),
            'get_promo_apply_to' => fn() => $this->setupservices->getPromoApplyTo('', '', $branch->id, $branch->token),
        ]));
    }

    public function syncMember()
    {
        $branch = $this->currentBranch();
        if (!$branch) {
            return $this->noBranchResponse();
        }

        return $this->respondGroup($this->runSteps([
            'get_member_type_list' => fn() => $this->setupservices->getMemberTypeList('', '', $branch->id, $branch->token),
            'get_member_list' => fn() => $this->setupservices->getMemberList('', '', $branch->id, $branch->token),
        ]));
    }

    public function syncUser()
    {
        $branch = $this->currentBranch();
        if (!$branch) {
            return $this->noBranchResponse();
        }

        return $this->respondGroup($this->runSteps([
            // get_menu_app DULUAN -- mr_role_access.menu_id refer ke mr_menu_app.id, kalau
            // menu_app-nya belum ke-sync duluan, data role_access nunjuk ke ID yang gak ada
            // (disepakati 2026-08-31, lihat riwayat obrolan soal ini).
            'get_menu_app' => fn() => $this->setupservices->getMenuApp('', '', $branch->id, $branch->token),
            'get_master_user' => fn() => $this->setupservices->getMasterUser('', '', $branch->id, $branch->token),
            'get_master_role_access' => fn() => $this->setupservices->getMasterRoleAccess('', '', $branch->id, $branch->token),
        ]));
    }

    public function syncMenu()
    {
        $branch = $this->currentBranch();
        if (!$branch) {
            return $this->noBranchResponse();
        }

        return $this->respondGroup($this->runSteps([
            'get_category_list' => fn() => $this->setupservices->getCategoryList('', '', $branch->id, $branch->token),
            'get_subcategory_list' => fn() => $this->setupservices->getSubCategoryList('', '', $branch->id, $branch->token),
            'get_item' => fn() => $this->setupservices->getMasterItem('', '', $branch->id, $branch->token),
            'get_item_conv' => fn() => $this->setupservices->getMasterItemConv('', '', $branch->id, $branch->token),
            'get_item_package' => fn() => $this->setupservices->getMasterItemPackage('', '', $branch->id, $branch->token),
            'get_item_package_group' => fn() => $this->setupservices->getMasterItemPackageGroup('', '', $branch->id, $branch->token),
            'get_item_package_detail' => fn() => $this->setupservices->getMasterItemPackageDetail('', '', $branch->id, $branch->token),
            'get_item_package_detail_pricelist' => fn() => $this->setupservices->getMasterItemPackageDetailPricelist('', '', $branch->id, $branch->token),
            'get_pricelist' => fn() => $this->setupservices->getMasterPricelist('', '', $branch->id, $branch->token),
            'get_pricelist_detail' => fn() => $this->setupservices->getMasterPricelistDetail('', '', $branch->id, $branch->token),
        ]));
    }

    public function syncBranchSetting()
    {
        $branch = $this->currentBranch();
        if (!$branch) {
            return $this->noBranchResponse();
        }

        return $this->respondGroup($this->runSteps([
            'get_data_branch' => fn() => $this->setupservices->getDatabranch('', '', $branch->id, $branch->token),
            'get_station_list' => fn() => $this->setupservices->getStationList('', '', $branch->id, $branch->token),
            'get_terminal' => fn() => $this->setupservices->getTerminal('', '', $branch->id, $branch->token),
            'get_tax' => fn() => $this->setupservices->getTax('', '', $branch->id, $branch->token),
            'get_visit_purpose' => fn() => $this->setupservices->getMasterVisitPurpose('', '', $branch->id, $branch->token),
            'get_branch_visit_purpose' => fn() => $this->setupservices->getMasterBranchVisitPurpose('', '', $branch->id, $branch->token),
            'get_payment_method' => fn() => $this->setupservices->getMasterPaymentMethod('', '', $branch->id, $branch->token),
            'get_payment_method_visit_purpose' => fn() => $this->setupservices->getMasterPaymentMethodVisitPurpose('', '', $branch->id, $branch->token),
            'get_branch_ops_setting' => fn() => $this->setupservices->getMasterBranchOpsSetting('', '', $branch->id, $branch->token),
            'get_master_image_customer_display' => fn() => $this->setupservices->getMasterImageCustomerDisplay('', '', $branch->id, $branch->token),
            'get_master_image_kiosk' => fn() => $this->setupservices->getMasterImageKiosk('', '', $branch->id, $branch->token),
        ]));
    }

    public function syncMasterSetting()
    {
        $branch = $this->currentBranch();
        if (!$branch) {
            return $this->noBranchResponse();
        }

        return $this->respondGroup($this->runSteps([
            'get_payment_method_type' => fn() => $this->setupservices->getMasterPaymentMethodType('', '', $branch->id, $branch->token),
            'get_payment_method_group' => fn() => $this->setupservices->getMasterPaymentMethodGroup('', '', $branch->id, $branch->token),
        ]));
    }

    // syncSales: BEDA ARAH -- push data lokal ke ERP (reuse App\Services\PushDataServices, 6
    // fungsi yang SAMA dipakai background job sync:push), bukan pull kayak 7 method di atas.
    // Urutan WAJIB dayshift dulu (pos_order.dayshift_ulid ngerujuk ke situ), sama persis
    // urutan yang dipakai SyncPush command & DayShiftServices::EndDay().
    public function syncSales()
    {
        $pushService = new PushDataServices();

        $steps = [
            'dayshift' => fn() => $pushService->pushDataDayShift(),
            'dayshift_detail' => fn() => $pushService->pushDataDayShiftDetail(),
            'order' => fn() => $pushService->pushDataOrder(),
            'order_detail' => fn() => $pushService->pushDataOrderDetail(),
            'order_detail_package' => fn() => $pushService->pushDataOrderDetailPackage(),
            'order_payment' => fn() => $pushService->pushDataOrderPayment(),
        ];

        $results = [];
        $hasError = false;
        foreach ($steps as $key => $callable) {
            try {
                $callable();
                $results[$key] = ['code' => 0, 'message' => 'ok'];
            } catch (\Throwable $e) {
                Log::channel('jobs')->error("SyncGroupController syncSales [{$key}]: {$e->getMessage()}");
                $results[$key] = ['code' => 100, 'message' => $e->getMessage()];
                $hasError = true;
            }
        }

        return response()->json(['code' => $hasError ? 100 : 0, 'data' => $results]);
    }
}
