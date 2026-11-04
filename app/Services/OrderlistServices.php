<?php

namespace App\Services;

use App\Models\TableSectionModel;
use App\Models\TrOrderModel;
use Illuminate\Support\Facades\DB;

class OrderlistServices
{
  public static function getOrderList($tablesection_id)
  {
    try {

      $data_tablesection = TableSectionModel::where('id', $tablesection_id)->first();

      $orderList = [];

      if ($data_tablesection->type == 'takeaway') {
        $orderList = TrOrderModel::where('order_source', 'pos')
          ->where('table_section_id', $tablesection_id)
          ->whereIn('status', ['pending', 'hold'])
          ->orderBy('created_at', 'desc')->get();
      } else if ($data_tablesection->type == 'dinein') {
        $orderList = DB::select("
                    SELECT
                    mt.*,
                    tro.order_number,
                    tro.order_in,
                    tro.status

                    FROM mr_table mt
                    LEFT JOIN tr_order tro on tro.table_id = mt.id AND tro.status IN ('pending', 'hold')
                    WHERE mt.table_section_id = ?", [$data_tablesection->id]);
      }


      return $orderList;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  // getOrderListNew (2026-11-04): endpoint BARU terpisah (by-tablesection-new), TIDAK
  // menggantikan/mengubah getOrderList() di atas sama sekali -- shape response beda total,
  // dibungkus object {id,name,type,list} (bukan array polos kayak getOrderList()).
  //
  // BEDA UTAMA dari getOrderList():
  // - dinein: `list` isinya daftar MEJA (mr_table) yang tiap baris punya `order_detail` (object
  //   tunggal/null -- BUKAN array, 1 meja dipastikan maks 1 order aktif) + `status_table`
  //   (available/booked/dining/billed, lihat helper resolveStatusTable()).
  // - takeaway: `list` isinya daftar ORDER langsung (gak ada konsep meja), shape tiap baris SAMA
  //   field dengan `order_detail` di atas, flat (gak ada table_id/dll karena takeaway emang gak
  //   wajib meja).
  // - FILTER order_source='pos' SEKARANG KONSISTEN di keduanya (dinein MAUPUN takeaway) --
  //   beda dari getOrderList() yang cabang dinein-nya SAMA SEKALI gak filter order_source (lihat
  //   catatan di ORDER LIST.md bagian "Ringkasan" soal gap ini di endpoint lama).
  // - Field tambahan via LEFT JOIN: terminal_name (mr_terminal), created_by_name (mr_user.fullname),
  //   member_name (mr_member.name) -- semua LEFT JOIN biar order/meja tetap muncul walau
  //   relasinya kosong/sudah terhapus (nama jadi null, bukan baris ikut hilang).
  public static function getOrderListNew($tablesection_id)
  {
    try {
      $data_tablesection = TableSectionModel::where('id', $tablesection_id)->first();
      if (!$data_tablesection) {
        throw new \Exception('invalid table section!');
      }

      // query order aktif + JOIN nama -- SAMA PERSIS dipakai dinein maupun takeaway, cuma beda
      // filter table_id vs table_section_id di caller masing-masing.
      $baseOrderQuery = function () {
        return TrOrderModel::query()
          ->leftJoin('mr_terminal', 'mr_terminal.id', '=', 'tr_order.terminal_id')
          ->leftJoin('mr_user', 'mr_user.id', '=', 'tr_order.created_by')
          ->leftJoin('mr_member', 'mr_member.id', '=', 'tr_order.member_id')
          ->where('tr_order.order_source', 'pos')
          ->whereIn('tr_order.status', ['pending', 'hold'])
          ->select(
            'tr_order.order_number',
            'tr_order.order_name',
            'tr_order.order_type',
            'tr_order.status',
            'tr_order.order_in',
            'tr_order.pax',
            'tr_order.total_item',
            'tr_order.total_billing',
            'tr_order.print_ke',
            'tr_order.table_id',
            'tr_order.terminal_id',
            'mr_terminal.name as terminal_name',
            'tr_order.created_by',
            'mr_user.fullname as created_by_name',
            'tr_order.member_id',
            'mr_member.name as member_name'
          );
      };

      $list = [];

      if ($data_tablesection->type == 'takeaway') {
        $orders = $baseOrderQuery()
          ->where('tr_order.table_section_id', $data_tablesection->id)
          ->orderBy('tr_order.created_at', 'desc')
          ->get();

        foreach ($orders as $order) {
          $list[] = [
            'order_number' => $order->order_number,
            'order_name' => $order->order_name,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'order_in' => $order->order_in,
            'pax' => $order->pax,
            'total_item' => $order->total_item,
            'total_billing' => $order->total_billing,
            'print_ke' => $order->print_ke,
            'terminal_id' => $order->terminal_id,
            'terminal_name' => $order->terminal_name,
            'created_by' => $order->created_by,
            'created_by_name' => $order->created_by_name,
            'member_id' => $order->member_id,
            'member_name' => $order->member_name,
          ];
        }
      } else if ($data_tablesection->type == 'dinein') {
        $tables = DB::table('mr_table')->where('table_section_id', $data_tablesection->id)->get();

        $orders = $baseOrderQuery()
          ->where('tr_order.table_section_id', $data_tablesection->id)
          ->get();

        // index by table_id -- 1 meja dipastikan maks 1 order aktif (guard sudah ada di
        // SaveOrder(), "This table already has a pending order!"), jadi aman pakai keyBy.
        $orderByTableId = [];
        foreach ($orders as $order) {
          $orderByTableId[$order->table_id] = $order;
        }

        foreach ($tables as $table) {
          $order = $orderByTableId[$table->id] ?? null;

          $orderDetail = null;
          if ($order) {
            $orderDetail = [
              'order_number' => $order->order_number,
              'order_name' => $order->order_name,
              'order_type' => $order->order_type,
              'status' => $order->status,
              'order_in' => $order->order_in,
              'pax' => $order->pax,
              'total_item' => $order->total_item,
              'total_billing' => $order->total_billing,
              'print_ke' => $order->print_ke,
              'terminal_id' => $order->terminal_id,
              'terminal_name' => $order->terminal_name,
              'created_by' => $order->created_by,
              'created_by_name' => $order->created_by_name,
              'member_id' => $order->member_id,
              'member_name' => $order->member_name,
            ];
          }

          $list[] = [
            'table_id' => $table->id,
            'table_name' => $table->name,
            'type' => $table->type,
            'height' => $table->height,
            'width' => $table->width,
            'pos_x' => $table->pos_x,
            'pos_y' => $table->pos_y,
            'table_seat' => $table->table_seat,
            'minimum_billing' => $table->minimum_billing,
            'available_for_book' => $table->available_for_book,
            'status_table' => self::resolveStatusTable($order),
            'order_detail' => $orderDetail,
          ];
        }
      }

      return [
        'id' => $data_tablesection->id,
        'name' => $data_tablesection->name,
        'type' => $data_tablesection->type,
        'list' => $list,
      ];
    } catch (\Throwable $e) {
      return $e;
    }
  }

  // resolveStatusTable: status visual 1 meja (dinein doang) -- available/booked/dining/billed,
  // lowercase (disepakati eksplisit). $order null = belum ada order aktif di meja itu. print_ke
  // DIANDALKAN sebagai penentu "udah di-print bill apa belum" -- lihat OrderServices::SaveOrder()
  // yang sekarang reset print_ke=0 begitu ada item BARU ditambahkan ke order existing, biar
  // status balik ke "dining" kalau ada menu baru yang belum ke-bill (bukan nyangkut "billed"
  // selamanya begitu pernah print sekali).
  private static function resolveStatusTable($order)
  {
    if (!$order) {
      return 'available';
    }
    if ((int) $order->total_item === 0) {
      return 'booked';
    }
    if ((int) $order->print_ke > 0) {
      return 'billed';
    }
    return 'dining';
  }

  // getAllPendingOrders: SEMUA order pending/hold lintas table section -- BEDA dari
  // getOrderList() di atas (yang WAJIB scoped ke 1 table_section_id). Dipakai lock screen
  // (LockscreenPage.vue) biar staff bisa liat sekilas order yang lagi nyangkut TANPA perlu
  // login/pilih table section dulu -- disepakati 2026-08-31, sebelumnya lock screen malah
  // nyalin utuh UI table-section-tabs dari listTablePage.vue yang gak relevan buat konteks
  // pra-login.
  public static function getAllPendingOrders()
  {
    try {
      return TrOrderModel::where('order_source', 'pos')
        ->whereIn('status', ['pending', 'hold'])
        ->orderBy('created_at', 'desc')
        ->get();
    } catch (\Throwable $e) {
      return $e;
    }
  }
}
