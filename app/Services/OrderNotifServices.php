<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// OrderNotifServices: sumber toast "ada order baru yang perlu di-confirm" di frontend POS --
// DIGABUNG dari 2 sumber (mobile & kiosk), 1 feed aja, staff gak perlu mikirin asalnya dari
// mana. Masing-masing sumber punya tabel penanda SENDIRI (sengaja terpisah dari tr_order):
// - mb_order (LOKAL, hasil MobileOrderPullServices::processOrder()) -- flag_confirm.
// - tr_kiosk_order_notif (hasil PaymentServices::SavePayment(), order_source='kiosk') --
//   flag_confirm.
// Dua-duanya MURNI acknowledgment UI ("staff udah liat notif ini"), gak ngaruh ke proses order
// sama sekali. Info yang ditampilin (nama/HP/jumlah item/total/jam) di-JOIN dari tr_order --
// tabel penanda itu sendiri cuma nyimpen order_number. order_name buat order mobile diisi nama
// member (lihat MobileOrderPullServices::processOrder()), buat kiosk diisi apa yang customer
// ketik sendiri (lihat OrderServices::SaveOrder()) -- bisa kosong kalau kiosk gak diisi.
class OrderNotifServices
{
    // getPendingNotif: dipanggil frontend lewat polling. UNION ALL 2 sumber, order_in ASC --
    // yang paling lama nunggu duluan muncul (antrean toast gabungan, gak dipisah per sumber).
    public static function getPendingNotif()
    {
        $mobile = DB::table('mb_order as mo')
            ->join('tr_order as tro', 'tro.order_number', '=', 'mo.order_number')
            ->where('mo.flag_confirm', false)
            ->select(
                'mo.order_number',
                'tro.order_name',
                'tro.customer_phone_number',
                'tro.total_item',
                'tro.total_billing',
                'tro.order_in',
                DB::raw("'mobile' as order_source")
            );

        $kiosk = DB::table('tr_kiosk_order_notif as ko')
            ->join('tr_order as tro', 'tro.order_number', '=', 'ko.order_number')
            ->where('ko.flag_confirm', false)
            ->select(
                'ko.order_number',
                'tro.order_name',
                'tro.customer_phone_number',
                'tro.total_item',
                'tro.total_billing',
                'tro.order_in',
                DB::raw("'kiosk' as order_source")
            );

        return $mobile->unionAll($kiosk)->orderBy('order_in', 'asc')->get();
    }

    // confirm: dipanggil frontend pas toast di-dismiss. order_number UNIK lintas sumber (format
    // beda per generator, mobile vs kiosk gak akan pernah collide), jadi cukup coba update
    // di 2 tabel sekaligus -- yang gak match otomatis 0 baris ke-update (no-op), gak perlu
    // deteksi dulu row-nya ada di tabel mana.
    public static function confirm(string $orderNumber): bool
    {
        $updated = DB::table('mb_order')
            ->where('order_number', $orderNumber)
            ->update(['flag_confirm' => true]);

        $updated += DB::table('tr_kiosk_order_notif')
            ->where('order_number', $orderNumber)
            ->update(['flag_confirm' => true]);

        return $updated > 0;
    }
}
