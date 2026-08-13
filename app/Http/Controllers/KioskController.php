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

    // GetBannerImageKiosk: daftar gambar banner/slideshow buat layar Kiosk, filter apply_for =
    // 'cd_kiosk' udah fix di query (bukan parameter client, request gak dipakai) -- urut
    // sequence. image_src udah path lokal POS (didownload pas pull, lihat
    // SetupServices::getMasterImageList()), bukan path ERP.
    public function GetBannerImageKiosk(Request $request)
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

    // GetOrderDetail: header order + list item (`items[]`) by order_number -- dipakai dobel:
    // preview abis save-order (sebelum bayar) dan detail pas order di-tap dari GetOrderHistory.
    // Header-nya SENGAJA disamain kolomnya sama GetOrderHistory (status/member_name/
    // payment_method_id/dkk, pola join yang sama persis -- payment_method_id dari attempt
    // TERAKHIR tr_kiosk_payment_request, bukan tr_order_payment, lihat catatan di
    // GetOrderHistory), plus tambahan sub_total/total_tax/total_discount yang emang cuma
    // relevan buat tampilan detail (bukan list). Join mr_item_conv -> mr_item persis pola
    // OrderServices::viewOrder() (sumber nama menu yang "real", trod.menu_id itu FK ke
    // mr_item_conv, bukan langsung ke mr_item). Item package (`tr_order_detail_package`)
    // di-nest ke `package[]` per item, query terpisah per-ulid sama kayak pola aslinya di
    // OrderServices -- jumlah item per order kecil, N+1 di sini gak masalah.
    public function GetOrderDetail(Request $request, string $order_number)
    {
        try {
            $order = DB::select("SELECT
                    o.order_number,
                    o.payment_number,
                    o.status,
                    o.order_in,
                    o.order_name,
                    o.sub_total,
                    o.total_tax,
                    o.total_discount,
                    o.total_billing,
                    o.total_item,
                    o.customer_phone_number,
                    m.name as member_name,
                    latest.payment_method_id,
                    pm.name as payment_method
                    FROM tr_order o
                    LEFT JOIN mr_member m ON m.id = o.member_id
                    LEFT JOIN (
                        SELECT kpr.order_number, kpr.payment_method_id
                        FROM tr_kiosk_payment_request kpr
                        INNER JOIN (
                            SELECT order_number, MAX(created_at) as max_created_at
                            FROM tr_kiosk_payment_request
                            GROUP BY order_number
                        ) latest_kpr ON latest_kpr.order_number = kpr.order_number
                            AND latest_kpr.max_created_at = kpr.created_at
                    ) latest ON latest.order_number = o.order_number
                    LEFT JOIN mr_payment_method pm ON pm.id = latest.payment_method_id
                    WHERE o.order_number = ?", [$order_number]);
            $order = $order[0] ?? null;

            if (!$order) {
                return response()->json([
                    'code' => 100,
                    'message' => 'order tidak ditemukan',
                ]);
            }

            $items = DB::select("SELECT
                    trod.ulid,
                    trod.menu_id,
                    mri.name as menu_name,
                    trod.qty,
                    trod.notes,
                    trod.base_price as price,
                    trod.discount_value,
                    trod.tax_value,
                    trod.total
                    FROM tr_order_detail trod
                    JOIN mr_item_conv mric ON mric.id = trod.menu_id
                    JOIN mr_item mri ON mri.id = mric.item_id
                    WHERE trod.order_number = ?", [$order_number]);

            foreach ($items as $item) {
                $item->package = DB::select("SELECT
                        trodp.menu_id,
                        mri.name as menu_name,
                        trodp.qty,
                        trodp.notes,
                        trodp.base_price as price,
                        trodp.discount_value,
                        trodp.tax_value,
                        trodp.total
                        FROM tr_order_detail_package trodp
                        JOIN mr_item_conv mric ON mric.id = trodp.menu_id
                        JOIN mr_item mri ON mri.id = mric.item_id
                        WHERE trodp.tr_order_detail_ulid = ?", [$item->ulid]);
                unset($item->ulid);
            }

            $order->items = $items;

            return response()->json([
                'code' => 0,
                'data' => $order,
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

    // CheckPaymentStatus: polling status pembayaran -- cuma butuh order_number (bukan order_id,
    // Kiosk gak pernah pegang itu, lihat PaymentGatewayServices::CheckStatus()). Kalau statusnya
    // 'settlement', SavePayment() otomatis ke-trigger di dalam sana -- print kitchen (yang
    // sengaja ditunda, lihat SaveOrder()) baru jalan di titik ini.
    public function CheckPaymentStatus(Request $request, string $order_number)
    {
        try {
            $data = (new PaymentGatewayServices())->CheckStatus($order_number);

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

    // GetOrderHistory: list header order kiosk (order_source = 'kiosk'), filter date range by
    // order_in (bukan order_date -- order_in dateTime, punya jam, dan konsisten sama filter
    // tanggal yang dipakai modul lain kayak SalesServices/DayShiftServices) + optional
    // terminal_id. Belum termasuk list item -- detail per-order nyusul endpoint terpisah.
    // date_from/date_to optional, default hari ini kalau gak dikirim (format Y-m-d) --
    // date_to inclusive (dibandingin sampai < date_to + 1 hari). member_name pakai LEFT JOIN
    // (bukan inner) -- order tanpa member_id gak match mr_member, jangan sampai ke-drop dari
    // list gara-gara inner join.
    //
    // payment_method_id/payment_method diambil dari attempt TERAKHIR di tr_kiosk_payment_request
    // (bukan dari tr_order_payment) -- sengaja, biar tetap ada meski order-nya masih 'pending'
    // (belum kebayar). Kiosk butuh payment_method_id ini buat retry langsung dari list history:
    // order pending di-tap -> panggil ulang payment/request pakai payment_method_id yang sama,
    // gak perlu customer milih payment method lagi dari awal.
    public function GetOrderHistory(Request $request)
    {
        try {
            $dateFrom = $request->query('date_from') ?: now()->toDateString();
            $dateTo = $request->query('date_to') ?: now()->toDateString();
            $terminalId = $request->query('terminal_id');

            $where = "o.order_source = 'kiosk' AND o.order_in >= ? AND o.order_in < DATE_ADD(?, INTERVAL 1 DAY)";
            $bindings = [$dateFrom, $dateTo];

            if ($terminalId) {
                $where .= " AND o.terminal_id = ?";
                $bindings[] = $terminalId;
            }

            $data = DB::select("SELECT
                    o.order_number,
                    o.payment_number,
                    o.status,
                    o.order_in,
                    o.order_name,
                    o.total_billing,
                    o.total_item,
                    o.customer_phone_number,
                    m.name as member_name,
                    latest.payment_method_id,
                    pm.name as payment_method
                    FROM tr_order o
                    LEFT JOIN mr_member m ON m.id = o.member_id
                    LEFT JOIN (
                        SELECT kpr.order_number, kpr.payment_method_id
                        FROM tr_kiosk_payment_request kpr
                        INNER JOIN (
                            SELECT order_number, MAX(created_at) as max_created_at
                            FROM tr_kiosk_payment_request
                            GROUP BY order_number
                        ) latest_kpr ON latest_kpr.order_number = kpr.order_number
                            AND latest_kpr.max_created_at = kpr.created_at
                    ) latest ON latest.order_number = o.order_number
                    LEFT JOIN mr_payment_method pm ON pm.id = latest.payment_method_id
                    WHERE $where
                    ORDER BY o.order_in DESC", $bindings);

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

    // CancelOrder: batalin order kiosk sebelum bayar -- reuse OrderServices::CancelOrder() yang
    // sama dipakai POS (udah guard cuma order status pending/hold yang bisa di-cancel, order yang
    // udah paid otomatis ketolak). Sebelum itu, kalau order ini masih punya attempt payment
    // pending (customer sempat minta QR tapi belum bayar), attempt itu ikut di-cancel ke Midtrans
    // juga (lihat PaymentGatewayServices::CancelPendingAttempt()) -- biar gak nyangkut sendiri.
    public function CancelOrder(Request $request)
    {
        try {
            $order_number = $request->input('order_number');
            $notes = $request->input('notes', '');

            if (!$order_number) {
                return response()->json([
                    'code' => 100,
                    'message' => 'order_number wajib diisi',
                ]);
            }

            (new PaymentGatewayServices())->CancelPendingAttempt($order_number);
            $message = OrderServices::CancelOrder($order_number, $notes);

            return response()->json([
                'code' => 0,
                'message' => $message,
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

        $customerPhoneNumber = $data['customer_phone_number'] ?? null;

        return [
            // Kiosk gak pernah edit order existing (beda dari POS yang bisa hold/reopen) --
            // selalu order baru, jadi orderNumber dipaksa kosong di sini, gak dipercaya dari
            // client (client gak perlu kirim field ini lagi sama sekali).
            'orderNumber' => '',
            'orderName' => $data['order_name'] ?? '',
            'customerPhoneNumber' => $customerPhoneNumber,
            'terminalId' => $data['terminal_id'] ?? null,
            'visitPurposeId' => $data['visit_purpose_id'] ?? null,
            'priceListId' => $data['price_list_id'] ?? null,
            'orderPax' => $data['order_pax'] ?? null,
            'totalItem' => $data['total_item'] ?? null,
            'subTotal' => $data['sub_total'] ?? null,
            'totalTax' => $data['total_tax'] ?? null,
            'totalBilling' => $data['total_billing'] ?? null,
            'totalDiscount' => $data['total_discount'] ?? 0,
            // memberId gak lagi dipercaya dari client -- di-derive dari customerPhoneNumber
            // (kalau ada isinya) lewat data lokal mr_member (bukan live ke ERP, ini jalur
            // kritis nyimpen order, gak boleh gantung koneksi ke ERP -- live check ada
            // sendiri di GET /api/kiosk/member/check/{phone_number}). Kosong/gak ketemu ->
            // tetap null, order tetap kesimpen (bukan syarat wajib jadi member).
            'memberId' => $this->resolveMemberIdByPhone($customerPhoneNumber),
            'listOrder' => $listOrder,
        ];
    }

    // resolveMemberIdByPhone: cari member_id dari nomor HP yang dikirim customer, pakai data
    // lokal mr_member (sync, bukan live ke ERP). Null/string kosong -> skip, gak usah dicari.
    private function resolveMemberIdByPhone(?string $phoneNumber): ?int
    {
        if (empty($phoneNumber)) {
            return null;
        }

        $member = DB::table('mr_member')
            ->where('phone_number', $phoneNumber)
            ->where('is_active', 1)
            ->first();

        return $member->id ?? null;
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
