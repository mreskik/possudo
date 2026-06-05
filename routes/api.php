<?php

use App\Http\Controllers\BranchMenuController;
use App\Http\Controllers\CheckerController;
use App\Http\Controllers\DayShiftController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderlistController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

// Route::apiResource('order', OrderController::class);


Route::prefix('setup')->group(function () {
  Route::post('get_branch_list', [SetupController::class, 'getBranchList']);
  Route::post('get_data_branch/{branch_id}', [SetupController::class, 'getDataBranch']);
  Route::post('get_station_list/{branch_id}', [SetupController::class, 'getStationList']);
  Route::post('get_category_list/{branch_id}', [SetupController::class, 'getCategoryList']);
  Route::post('get_subcategory_list/{branch_id}', [SetupController::class, 'getSubCategoryList']);
  Route::post('get_tablesection_list/{branch_id}', [SetupController::class, 'getTableSectionList']);
  Route::post('get_table/{branch_id}', [SetupController::class, 'getTable']);
  Route::post('get_tax/{branch_id}', [SetupController::class, 'getTax']);
  Route::post('get_terminal/{branch_id}', [SetupController::class, 'getTerminal']);

  Route::post('get_item/{branch_id}', [SetupController::class, 'getMasterItem']);
  Route::post('get_item_conv/{branch_id}', [SetupController::class, 'getMasterItemConv']);
  Route::post('get_item_package/{branch_id}', [SetupController::class, 'getMasterItemPackage']);
  Route::post('get_item_package_group/{branch_id}', [SetupController::class, 'getMasterItemPackageGroup']);
  Route::post('get_item_package_detail/{branch_id}', [SetupController::class, 'getMasterItemPackageDetail']);
  Route::post('get_pricelist/{branch_id}', [SetupController::class, 'getMasterPricelist']);
  Route::post('get_pricelist_detail/{branch_id}', [SetupController::class, 'getMasterPricelistDetail']);

  Route::post('get_payment_method/{branch_id}', [SetupController::class, 'getMasterPaymentMethod']);
  Route::post('get_payment_method_group/{branch_id}', [SetupController::class, 'getMasterPaymentMethodGroup']);
  Route::post('get_payment_method_type/{branch_id}', [SetupController::class, 'getMasterPaymentMethodType']);
  Route::post('get_payment_method_visit_purpose/{branch_id}', [SetupController::class, 'getMasterPaymentMethodVisitPurpose']);
  Route::post('get_branch_visit_purpose/{branch_id}', [SetupController::class, 'getMasterBranchVisitPurpose']);
  Route::post('get_visit_purpose/{branch_id}', [SetupController::class, 'getMasterVisitPurpose']);
  Route::get('install_success/{status}', [SetupController::class, 'ChangeStatusInstall']);
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
});

Route::prefix('order')->group(function () {
  Route::any('save-order', [OrderController::class, 'saveOrder']);
  Route::post('cancel-order', [OrderController::class, 'cancelOrder']);
  Route::get('view-order/{order_number}', [OrderController::class, 'viewOrder']);
  Route::get('only-view/{order_number}', [OrderController::class, 'onlyViewOrder']);
  Route::get('print-bill', [OrderController::class, 'PrintBill']);
});

Route::prefix('orderlist')->group(function () {
  Route::any('order-takeaway', [OrderlistController::class, 'getOrderlistTakaway']);
});
Route::prefix("sales")->group(function () {
  Route::get('get-sales-list', [SalesController::class, 'GetSalesList']);
  Route::get('view/{order_number}', [SalesController::class, 'ViewSales']);
  Route::get('reprint/{order_number}', [SalesController::class, 'Reprint']);
  Route::post('void', [SalesController::class, 'Void']);
});

Route::prefix('payment')->group(function () {
  Route::post('save-payment', [PaymentController::class, 'savePayment']);
});
Route::prefix('setting')->group(function () {
  Route::post('save', [SettingController::class, 'save']);
  Route::get('load', [SettingController::class, 'load']);
  Route::get('customer_display', [SettingController::class, 'customer_display']);
});

Route::prefix('dayshift')->group(function () {
  Route::get('get', [DayShiftController::class, 'CurrentDay']);
  Route::post('start', [DayShiftController::class, 'StartDay']);
  Route::get('end-shift/{dayshift_id}', [DayShiftController::class, 'Endshift']);
  Route::get('detail-list/{dayshift_id}', [DayShiftController::class, 'ListDayShiftDetail']);
  Route::post("end-day", [DayShiftController::class, 'EndDay']);
  // Route::get("report-all", [DayShiftController::class, 'ReportAll']);
  Route::get("report/{dayshift_id}", [DayShiftController::class, 'Report']);
  Route::get("dayshift-list", [DayShiftController::class, 'DayShiftList']);
  Route::get("print-report/{dayshift_id}", [DayShiftController::class, 'printReport']);
  Route::get("print-report-shift/{dayshift_detail_id}", [DayShiftController::class, 'printReportByShift']);
});


Route::prefix('cek')->group(function () {
  Route::any('cek', [CheckerController::class, 'cek']);
});


Route::prefix('branch-menu')->group(function () {
  Route::get('load', [BranchMenuController::class, 'load']);
  Route::post('save-soldout', [BranchMenuController::class, 'saveSoldout']);
  Route::post('save-stokqty', [BranchMenuController::class, 'saveStokQTY']);
});
