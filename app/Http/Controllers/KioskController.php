<?php

namespace App\Http\Controllers;

use App\Services\DayShiftServices;
use App\Services\MemberServices;
use App\Services\MenuServices;
use App\Services\OrderServices;
use App\Services\PaymentGatewayServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KioskController extends Controller
{
    // GetDayStatus: cek toko lagi buka apa engga -- gabungan jam operasional branch
    // (mr_branch_ops_setting, per hari) dan status dayshift (dayin_time keisi, dayout_time
    // masih null). Kiosk pakai ini sebelum ngizinin self-order. Lihat
    // DayShiftServices::GetKioskDayStatus() buat urutan cek lengkapnya.
    public function GetDayStatus(Request $request)
    {
        try {
            $data = DayShiftServices::GetKioskDayStatus();

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

    // GetBranchVisitPurposeList: baris mr_branch_visit_purpose yang dibolehin muncul di kanal
    // Kiosk (flag_kiosk = 1). Minimal (id/visit_purpose_id/name doang) -- detail lengkap
    // (service_charge/vat/pb1/order_fee/pricelist_id + pohon menu) ada di
    // GET /api/kiosk/visit-purpose/{id}.
    // `id` = id baris mr_branch_visit_purpose sendiri, `visit_purpose_id` = FK ke
    // mr_visit_purpose (ini yang dipakai buat manggil endpoint detail itu).
    public function GetBranchVisitPurposeList(Request $request)
    {
        try {
            $data = DB::select("SELECT
                    bvp.id,
                    bvp.visit_purpose_id,
                    mvp.name as visit_purpose_name
                    FROM mr_branch_visit_purpose bvp
                    JOIN mr_visit_purpose mvp on mvp.id = bvp.visit_purpose_id
                    WHERE bvp.flag_kiosk = 1");

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

    // GetImages: daftar gambar buat layar Kiosk (banner/slideshow), filter apply_for = 'cd_kiosk'
    // udah fix di query (bukan parameter client) -- urut sequence. image_src udah path lokal
    // POS (didownload pas pull, lihat SetupServices::getMasterImageList()), bukan path ERP.
    public function GetImages(Request $request)
    {
        try {
            $data = DB::select("SELECT
                    mil.image_src,
                    mil.sequence
                    FROM mr_image_list mil
                    JOIN mr_image mi ON mi.id = mil.master_image_id
                    JOIN mr_image_list_apply_for milaf ON milaf.master_image_list_id = mil.id
                    WHERE mi.is_active = 1 AND milaf.apply_for = 'cd_kiosk'
                    ORDER BY mil.sequence ASC");

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

    // CheckMemberByPhone: cek nomor HP udah kedaftar member apa belum -- LIVE ke ERP (bukan
    // baca mr_member lokal), lihat MemberServices::CheckByPhone(). data null (bukan error)
    // kalau nomornya belum kedaftar -- Kiosk yang mutusin mau nawarin daftar member atau enggak.
    public function CheckMemberByPhone(Request $request, string $phone_number)
    {
        try {
            $data = (new MemberServices())->CheckByPhone($phone_number);

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

    // GetTerminalDetail: detail 1 terminal by id, apa adanya dari mr_terminal (gak ada join).
    // Dipakai kiosk pas pertama kali device ini "kenal diri" abis pilih terminal
    // (lihat TerminalPage.vue).
    public function GetTerminalDetail(Request $request, int $id)
    {
        try {
            $data = DB::table('mr_terminal')->where('id', $id)->first();

            if (!$data) {
                return response()->json([
                    'code' => 100,
                    'message' => 'terminal tidak ditemukan',
                ]);
            }

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

    // GetOrderDetail: header order doang (order_name, sub_total, total_tax, total_discount,
    // total_billing) by order_number -- dipakai kiosk buat nampilin ringkasan/status order abis
    // save-order, sebelum bayar. Baru header, belum termasuk list item (nyusul kalau dibutuhin).
    public function GetOrderDetail(Request $request, string $order_number)
    {
        try {
            $data = DB::table('tr_order')
                ->select('order_number', 'order_name', 'sub_total', 'total_tax', 'total_discount', 'total_billing')
                ->where('order_number', $order_number)
                ->first();

            if (!$data) {
                return response()->json([
                    'code' => 100,
                    'message' => 'order tidak ditemukan',
                ]);
            }

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

    // GetPaymentMethodList: payment method yang bisa dipakai di Kiosk -- sementara filter-nya
    // cuma payment_gateway_code keisi (bukan null/kosong), karena Kiosk (self-service, gak ada
    // kasir) cuma boleh nawarin metode yang integrasi otomatis (gateway), bukan manual kayak
    // cash. Belum difilter per visit_purpose_id (lihat MasterController::GetPaymentMethod buat
    // pola itu) -- nyusul kalau dibutuhin.
    public function GetPaymentMethodList(Request $request)
    {
        try {
            $data = DB::table('mr_payment_method')
                ->select('id', 'name', 'code')
                ->whereNotNull('payment_gateway_code')
                ->where('payment_gateway_code', '!=', '')
                ->get();

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

    // RequestPayment: minta QR/dst ke payment gateway (APIANDORDER -> Midtrans) buat 1 order.
    // Wajib payment_method_id yang punya payment_gateway_code keisi (sama kayak filter di
    // GetPaymentMethodList()) -- kalau kosong/null, ditolak "payment method tidak didukung".
    public function RequestPayment(Request $request)
    {
        try {
            $order_number = $request->input('order_number');
            $payment_method_id = $request->input('payment_method_id');

            if (!$order_number || !$payment_method_id) {
                return response()->json([
                    'code' => 100,
                    'message' => 'order_number dan payment_method_id wajib diisi',
                ]);
            }

            $data = (new PaymentGatewayServices())->RequestPayment($order_number, (int) $payment_method_id);

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

    // GetBranchVisitPurposeDetail: detail 1 visit purpose (config vat/pb1/service_charge/
    // order_fee, plus rate-nya masing-masing) + pohon menu lengkap (category > subcategory >
    // item) buat visit purpose itu. Reuse MenuServices::GetMasterMenuList() apa adanya (tax
    // resolution & package handling-nya rumit, lebih aman reuse daripada ditulis ulang) --
    // filter satu baris sesuai $id, terus reshape ke snake_case.
    public function GetBranchVisitPurposeDetail(Request $request, int $id)
    {
        try {
            $allVisitPurpose = MenuServices::GetMasterMenuList();

            $vp = null;
            foreach ($allVisitPurpose as $row) {
                if ($row->id == $id) {
                    $vp = $row;
                    break;
                }
            }

            if (!$vp) {
                return response()->json([
                    'code' => 100,
                    'message' => 'visit purpose tidak ditemukan',
                ]);
            }

            // service_charge/vat/pb1 di mr_branch_visit_purpose itu tax_id (FK ke mr_tax),
            // bukan rate langsung -- resolve rate-nya sekalian biar kiosk gak perlu manggil
            // endpoint lain cuma buat tau persennya.
            $taxRates = DB::table('mr_tax')
                ->whereIn('id', [$vp->serviceCharge, $vp->vat, $vp->pb1])
                ->pluck('rate', 'id');

            $categories = [];
            foreach ($vp->menuPriceList as $cat) {
                $subcategories = [];
                foreach ($cat->subCategoryData as $sub) {
                    $items = [];
                    foreach ($sub->menuList as $item) {
                        $items[] = $this->mapKioskMenuItem($item);
                    }
                    $subcategories[] = [
                        'subcategory_id' => $sub->subCategoryId,
                        'subcategory_name' => $sub->SubCategoryName,
                        'icon_src' => $sub->subCategoryIconSrc,
                        'items' => $items,
                    ];
                }
                $categories[] = [
                    'category_id' => $cat->categoryId,
                    'category_name' => $cat->categoryName,
                    'subcategories' => $subcategories,
                ];
            }

            return response()->json([
                'code' => 0,
                'data' => [
                    'visit_purpose_id' => $vp->id,
                    'service_charge' => $vp->serviceCharge,
                    'service_charge_rate' => $taxRates[$vp->serviceCharge] ?? null,
                    'vat' => $vp->vat,
                    'vat_rate' => $taxRates[$vp->vat] ?? null,
                    'pb1' => $vp->pb1,
                    'pb1_rate' => $taxRates[$vp->pb1] ?? null,
                    'order_fee' => $vp->orderFee,
                    'menu_pricelist_id' => $vp->menuPriceListId,
                    'categories' => $categories,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // SaveOrder: wrapper tipis ke atas OrderServices::SaveOrder() yang sama persis dipakai POS --
    // gak ada logic order baru ditulis di sini. Client kirim snake_case (konvensi Kiosk), di-convert
    // ke camelCase di sini (mapKioskOrderPayload()) sebelum diteruskan, karena OrderServices masih
    // dipakai bareng POS yang camelCase. 2 hal yang di-resolve/dipaksa di sini, gak dipercaya dari
    // client:
    // - order_source di-hardcode 'kiosk' (bukan dari client), dipakai OrderServices buat nunda
    //   print kitchen sampai payment sukses (lihat SaveOrder()/PaymentServices::SavePayment()).
    // - table_section_id diambil dari mr_terminal.table_section_id (device Kiosk pra-dikonfigurasi
    //   ke 1 table section tetap), bukan dipilih user -- kalau kosong, terminal itu emang belum
    //   di-setup buat kiosk, gak bisa lanjut.
    public function SaveOrder(Request $request)
    {
        try {
            $terminal_id = $request->input('terminal_id');
            if (!$terminal_id) {
                return response()->json([
                    'code' => 100,
                    'message' => 'terminal_id wajib diisi',
                ]);
            }

            $terminal = DB::table('mr_terminal')->where('id', $terminal_id)->first();
            if (!$terminal) {
                return response()->json([
                    'code' => 100,
                    'message' => 'terminal tidak ditemukan',
                ]);
            }

            if (!$terminal->table_section_id) {
                return response()->json([
                    'code' => 100,
                    'message' => 'terminal ini belum dikonfigurasi table_section_id, hubungi admin',
                ]);
            }

            $mapped = $this->mapKioskOrderPayload($request->all());
            $mapped['orderSource'] = 'kiosk';
            $mapped['tableSectionId'] = $terminal->table_section_id;

            $request->replace($mapped);

            $order_number = OrderServices::SaveOrder($request);

            return response()->json([
                'code' => 0,
                'data' => [
                    'order_number' => $order_number,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // mapKioskOrderPayload: convert payload save-order dari snake_case (konvensi Kiosk) ke
    // camelCase yang diharapkan OrderServices::SaveOrder() (dipakai bareng POS, gak diubah).
    private function mapKioskOrderPayload(array $data): array
    {
        $listOrder = [];
        foreach ($data['list_order'] ?? [] as $item) {
            $menuPackageList = [];
            foreach ($item['menu_package_list'] ?? [] as $pkg) {
                $menuPackageList[] = [
                    'menuPackageId' => $pkg['menu_package_id'] ?? null,
                    'menuId' => $pkg['menu_id'] ?? null,
                    'qty' => $pkg['qty'] ?? null,
                    'flagInclusiveTax' => $pkg['flag_inclusive_tax'] ?? null,
                    'price' => $pkg['price'] ?? null,
                    'taxId' => $pkg['tax_id'] ?? null,
                    'taxType' => $pkg['tax_type'] ?? null,
                    'taxRate' => $pkg['tax_rate'] ?? null,
                    'taxValue' => $pkg['tax_value'] ?? null,
                    'promoId' => $pkg['promo_id'] ?? null,
                    'discountRate' => $pkg['discount_rate'] ?? null,
                    'discountValue' => $pkg['discount_value'] ?? null,
                    'total' => $pkg['total'] ?? null,
                    'notes' => $pkg['notes'] ?? null,
                ];
            }

            $listOrder[] = [
                'menuPricelistId' => $item['menu_pricelist_id'] ?? null,
                'menuId' => $item['menu_id'] ?? null,
                'qty' => $item['qty'] ?? null,
                'flagInclusiveTax' => $item['flag_inclusive_tax'] ?? null,
                'price' => $item['price'] ?? null,
                'taxId' => $item['tax_id'] ?? null,
                'taxType' => $item['tax_type'] ?? null,
                'taxRate' => $item['tax_rate'] ?? null,
                'taxValue' => $item['tax_value'] ?? null,
                'promoId' => $item['promo_id'] ?? null,
                'isFreeItemPromo' => $item['is_free_item_promo'] ?? false,
                'discountRate' => $item['discount_rate'] ?? null,
                'discountValue' => $item['discount_value'] ?? null,
                'afterDiscount' => $item['after_discount'] ?? null,
                'dpp' => $item['dpp'] ?? null,
                'total' => $item['total'] ?? null,
                'notes' => $item['notes'] ?? null,
                'menuPackageList' => $menuPackageList,
            ];
        }

        return [
            'orderNumber' => $data['order_number'] ?? '',
            'orderName' => $data['order_name'] ?? '',
            'customerPhoneNumber' => $data['customer_phone_number'] ?? null,
            'terminalId' => $data['terminal_id'] ?? null,
            'visitPurposeId' => $data['visit_purpose_id'] ?? null,
            'priceListId' => $data['price_list_id'] ?? null,
            'orderPax' => $data['order_pax'] ?? null,
            'totalItem' => $data['total_item'] ?? null,
            'subTotal' => $data['sub_total'] ?? null,
            'totalTax' => $data['total_tax'] ?? null,
            'totalBilling' => $data['total_billing'] ?? null,
            'totalDiscount' => $data['total_discount'] ?? 0,
            'memberId' => $data['member_id'] ?? null,
            'listOrder' => $listOrder,
        ];
    }

    // mapKioskMenuItem: reshape 1 item dari MenuServices::GetMasterMenuList() (camelCase, plus
    // packageList bertingkat) ke snake_case flat sesuai konvensi kiosk.
    private function mapKioskMenuItem($item)
    {
        $packageList = [];
        foreach ($item->packageList ?? [] as $pkg) {
            $menuPackageList = [];
            foreach ($pkg->menuPackageList ?? [] as $mpl) {
                $menuPackageList[] = [
                    'menu_package_id' => $mpl->menuPackageId,
                    'item_id' => $mpl->itemId,
                    'menu_name' => $mpl->menuName,
                    'menu_price' => $mpl->menuPrice,
                    'tax_type' => $mpl->taxType,
                    'bom_id' => $mpl->bomId,
                    'icon_src' => $mpl->iconSrc ?? null,
                    'tax_id' => $mpl->taxId,
                    'tax_rate' => $mpl->taxRate,
                ];
            }
            $packageList[] = [
                'package_id' => $pkg->packageId,
                'package_name' => $pkg->packageName,
                'min_qty' => $pkg->minQty,
                'max_qty' => $pkg->maxQty,
                'menu_package_list' => $menuPackageList,
            ];
        }

        return [
            'detail_pricelist_id' => $item->menuPricelistId,
            'item_id' => $item->itemId,
            'item_id_real' => $item->itemid_real,
            'menu_code' => $item->menuCode,
            'menu_name' => $item->menuName,
            'menu_color' => $item->menuColor,
            'image_src' => $item->imageSrc ?? null,
            'icon_src' => $item->iconSrc ?? null,
            'bom_id' => $item->bomId,
            'category_id' => $item->categoryId,
            'subcategory_id' => $item->subCategoryId,
            'menu_price' => $item->menuPrice,
            'flag_inclusive_tax' => $item->flagInclusiveTax,
            'tax_type' => $item->taxType,
            'stok_qty' => $item->stokQty,
            'flag_sold_out' => $item->flagSoldOut,
            'tax_id' => $item->taxId,
            'tax_rate' => $item->taxRate,
            'package_id_real' => $item->packageid_real ?? null,
            'separate_print_package' => $item->separatePrintPackage ?? null,
            'package_list' => $packageList,
        ];
    }
}
