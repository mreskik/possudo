<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchMenuController;
use App\Http\Controllers\CheckerController;
use App\Http\Controllers\DayShiftController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderlistController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PushDataController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

// Route::apiResource('order', OrderController::class);


Route::prefix('kiosk')->group(function () {
  Route::get('day-status', [KioskController::class, 'GetDayStatus']);
  Route::get('banner-image', [KioskController::class, 'GetBannerImageKiosk']);
  Route::get('member/check/{phone_number}', [KioskController::class, 'CheckMemberByPhone']);
  Route::get('branch-visit-purpose', [KioskController::class, 'GetBranchVisitPurposeList']);
  Route::get('branch-visit-purpose/{id}', [KioskController::class, 'GetBranchVisitPurposeDetail']);
  Route::get('terminal/{id}', [KioskController::class, 'GetTerminalDetail']);
  Route::post('save-order', [KioskController::class, 'SaveOrder']);
  Route::get('order/{order_number}', [KioskController::class, 'GetOrderDetail']);
  Route::get('payment-method', [KioskController::class, 'GetPaymentMethodList']);
  Route::post('payment/request', [KioskController::class, 'RequestPayment']);
  Route::get('payment/check-status/{order_number}', [KioskController::class, 'CheckPaymentStatus']);
});

Route::prefix('setup')->group(function () {
  Route::post('get_branch_list', [SetupController::class, 'getBranchList']);
  Route::post('get_data_branch/{branch_id}', [SetupController::class, 'getDataBranch']);
  Route::get('install_success/{status}', [SetupController::class, 'ChangeStatusInstall']);
});

// branch_id gak dikirim dari client -- mr_branch lokal cuma 1 baris (SyncController::currentBranch()).
Route::prefix('sync_pull')->group(function () {
  Route::get('get_data_branch', [SyncController::class, 'getDataBranch']);
  Route::get('get_station_list', [SyncController::class, 'getStationList']);
  Route::get('get_category_list', [SyncController::class, 'getCategoryList']);
  Route::get('get_subcategory_list', [SyncController::class, 'getSubCategoryList']);
  Route::get('get_tablesection_list', [SyncController::class, 'getTableSectionList']);
  Route::get('get_table', [SyncController::class, 'getTable']);
  Route::get('get_tax', [SyncController::class, 'getTax']);
  Route::get('get_terminal', [SyncController::class, 'getTerminal']);

  Route::get('get_item', [SyncController::class, 'getMasterItem']);
  Route::get('get_item_conv', [SyncController::class, 'getMasterItemConv']);
  Route::get('get_item_package', [SyncController::class, 'getMasterItemPackage']);
  Route::get('get_item_package_group', [SyncController::class, 'getMasterItemPackageGroup']);
  Route::get('get_item_package_detail', [SyncController::class, 'getMasterItemPackageDetail']);
  Route::get('get_pricelist', [SyncController::class, 'getMasterPricelist']);
  Route::get('get_pricelist_detail', [SyncController::class, 'getMasterPricelistDetail']);

  Route::get('get_payment_method', [SyncController::class, 'getMasterPaymentMethod']);
  Route::get('get_payment_method_group', [SyncController::class, 'getMasterPaymentMethodGroup']);
  Route::get('get_payment_method_type', [SyncController::class, 'getMasterPaymentMethodType']);
  Route::get('get_payment_method_visit_purpose', [SyncController::class, 'getMasterPaymentMethodVisitPurpose']);
  Route::get('get_branch_visit_purpose', [SyncController::class, 'getMasterBranchVisitPurpose']);
  Route::get('get_branch_ops_setting', [SyncController::class, 'getMasterBranchOpsSetting']);
  Route::get('get_master_image', [SyncController::class, 'getMasterImage']);
  Route::get('get_master_image_list', [SyncController::class, 'getMasterImageList']);
  Route::get('get_master_image_list_apply_for', [SyncController::class, 'getMasterImageListApplyFor']);
  Route::get('get_visit_purpose', [SyncController::class, 'getMasterVisitPurpose']);
  Route::get('get_master_user', [SyncController::class, 'getMasterUser']);
  Route::get('get_master_role_access', [SyncController::class, 'getMasterRoleAccess']);
  Route::get('get_menu_app', [SyncController::class, 'getMenuApp']);

  Route::get('get_promo_list', [SyncController::class, 'getPromoList']);
  Route::get('get_promo_branch', [SyncController::class, 'getPromoBranch']);
  Route::get('get_promo_visit_purpose', [SyncController::class, 'getPromoVisitPurpose']);
  Route::get('get_promo_type_member', [SyncController::class, 'getPromoTypeMember']);
  Route::get('get_promo_category', [SyncController::class, 'getPromoCategory']);
  Route::get('get_promo_sub_category', [SyncController::class, 'getPromoSubCategory']);
  Route::get('get_promo_item', [SyncController::class, 'getPromoItem']);
  Route::get('get_promo_day', [SyncController::class, 'getPromoDay']);
  Route::get('get_promo_time', [SyncController::class, 'getPromoTime']);

  Route::get('get_member_type_list', [SyncController::class, 'getMemberTypeList']);
  Route::get('get_member_list', [SyncController::class, 'getMemberList']);
});

Route::prefix('master')->group(function () {
  Route::get('table-section', [MasterController::class, 'getTableSection']);
  Route::get('table-section-table', [MasterController::class, 'getTableSectionTable']);
  Route::get('visit-purpose', [MasterController::class, 'getVisitPurpose']);
  Route::get('menu-list', [MasterController::class, 'GetMasterMenuList']);
  Route::get('branch', [MasterController::class, 'GetBranchDetail']);
  Route::get('payment-method/{visit_purpose_id}', [MasterController::class, 'GetPaymentMethod']);
  Route::get('station-list', [MasterController::class, 'GetStationList']);

  Route::get('category-list', [MasterController::class, 'GetCategoryList']);
  Route::get('subcategory-list', [MasterController::class, 'GetSubCategoryList']);

  Route::get('member-list', [MasterController::class, 'GetMemberList']);
  Route::get('member-type-list', [MasterController::class, 'GetMemberTypeList']);

  Route::get('terminal-list', [MasterController::class, 'GetTerminalList']);

  Route::get('promo-list/{visit_purpose_id}', [MasterController::class, 'GetPromoList']);
});

Route::prefix('order')->group(function () {
  Route::any('save-order', [OrderController::class, 'saveOrder']);
  Route::post('cancel-order', [OrderController::class, 'cancelOrder']);
  Route::get('view-order/{order_number}', [OrderController::class, 'viewOrder']);
  Route::get('only-view/{order_number}', [OrderController::class, 'onlyViewOrder']);
  Route::get('print-bill', [OrderController::class, 'PrintBill']);
  Route::get('list-table-by-section/{tablesection_id}', [OrderController::class, 'ListTableBySection']);
  Route::get('list-table-by-section-all/{tablesection_id}', [OrderController::class, 'ListTableBySectionAll']);
  Route::post('save-move-table', [OrderController::class, 'SaveMoveTable']);
  Route::post('save-move-item', [OrderController::class, 'SaveMoveItem']);
  Route::post('cancel-item', [OrderController::class, 'CancelOrderDetail']);
});

Route::prefix('orderlist')->group(function () {
  Route::any('by-tablesection/{tablesection_id}', [OrderlistController::class, 'getOrderlist']);
});
Route::prefix("sales")->group(function () {
  Route::get('get-sales-list', [SalesController::class, 'GetSalesList']);
  Route::get('view/{order_number}', [SalesController::class, 'ViewSales']);
  Route::get('reprint/{order_number}', [SalesController::class, 'Reprint']);
  Route::post('void', [SalesController::class, 'Void']);
});

Route::prefix('payment')->group(function () {
  Route::post('save-payment', [PaymentController::class, 'savePayment']);
  Route::post('edit-payment', [PaymentController::class, 'editPayment']);
  Route::get('view-payment/{order_number}', [PaymentController::class, 'viewPayment']);
});
Route::prefix('setting')->group(function () {
  Route::post('save', [SettingController::class, 'save']);
  Route::get('load', [SettingController::class, 'load']);
  Route::get('customer_display', [SettingController::class, 'customer_display']);
  Route::get('test-print-all', [SettingController::class, 'testPrintAll']);
  Route::get('test-print/{station_id}', [SettingController::class, 'testPrint']);
});

Route::prefix('dayshift')->group(function () {
  Route::get('get', [DayShiftController::class, 'CurrentDay']);
  Route::post('start', [DayShiftController::class, 'StartDay']);
  Route::get('end-shift/{dayshift_ulid}', [DayShiftController::class, 'Endshift']);
  Route::get('detail-list/{dayshift_ulid}', [DayShiftController::class, 'ListDayShiftDetail']);
  Route::post("end-day", [DayShiftController::class, 'EndDay']);
  // Route::get("report-all", [DayShiftController::class, 'ReportAll']);
  Route::get("report/{dayshift_ulid}", [DayShiftController::class, 'Report']);
  Route::get("dayshift-list", [DayShiftController::class, 'DayShiftList']);
  Route::get("print-report/{dayshift_ulid}", [DayShiftController::class, 'printReport']);
  Route::get("print-endday-report/{dayshift_ulid}", [DayShiftController::class, 'printEndayReport']);
  Route::get("print-report-dayshift/{dayshift_ulid}", [DayShiftController::class, 'printReportDaysift']);
  Route::get("print-report-shift/{dayshift_detail_ulid}", [DayShiftController::class, 'printReportByShift']);
  Route::get("print-current-shift-report/{dayshift_ulid}", [DayShiftController::class, 'printCurrentShiftReport']);
  Route::get("pending-order", [DayShiftController::class, 'pendingOrder']);
});


Route::prefix('cek')->group(function () {
  Route::any('cek', [CheckerController::class, 'cek']);
});

Route::prefix('auth')->group(function () {
  Route::post('login', [AuthController::class, 'login']);
  Route::get('logout', [AuthController::class, 'logout']);
  Route::get('info', [AuthController::class, 'info']);
});


Route::prefix('branch-menu')->group(function () {
  Route::get('load', [BranchMenuController::class, 'load']);
  Route::post('save-soldout', [BranchMenuController::class, 'saveSoldout']);
  Route::post('save-stokqty', [BranchMenuController::class, 'saveStokQTY']);
});


//pushdata ke server erp
Route::prefix('push')->group(function () {
  Route::get('data-order', [PushDataController::class, 'PushDataOrder']);
  Route::get('data-order-detail', [PushDataController::class, 'PushDataOrderDetail']);
  Route::get('data-order-detail-package', [PushDataController::class, 'PushDataOrderDetailPackage']);
  Route::get('data-order-payment', [PushDataController::class, 'PushDataOrderPayment']);
  Route::get('data-dayshift', [PushDataController::class, 'PushDataDayShift']);
  Route::get('data-dayshift-detail', [PushDataController::class, 'PushDataDayShiftDetail']);
});
