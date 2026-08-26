<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// MobileOrderPullServices: logic inti buat background job `mobile-order:pull` (worker yang narik
// mb_order paid dari sudocore2 lewat APIANDORDER, masuk ke tr_order lokal). Dipisah dari Command
// (App\Console\Commands\PullMobileOrder) biar Command-nya tetap tipis (cuma orkestrasi loop),
// method di sini yang beneran ngerjain -- gampang dipanggil manual/dites terpisah dari loop-nya.
//
// Dibangun BERTAHAP -- baru ada resolveWorkerTerminal() (langkah 1, "cari terminal worker-nya").
// fetchPending()/processOrder() nyusul.
class MobileOrderPullServices
{
    // resolveWorkerTerminal: ambil semua terminal lokal (mr_terminal) yang tipenya
    // "Worker Mobile Customer" (join mr_pos_type via device_type, BUKAN hardcode pos_type_id --
    // biar gak nyimpang kalau id-nya beda antar environment, walau di migration awal kita
    // pastiin id=4 di dua sisi). Diurutin id ASC -- kalau kebetulan ada lebih dari 1 terminal
    // worker terdaftar (belum ada aturan "harus cuma 1"), yang id-nya paling kecil (paling
    // lama didaftarin) dianggap yang utama.
    //
    // is_active=true doang yang diambil -- terminal yang dinonaktifin admin gak boleh kepake
    // proses ini.
    public function resolveWorkerTerminal()
    {
        return DB::table('mr_terminal as t')
            ->join('mr_pos_type as pt', 'pt.id', '=', 't.pos_type_id')
            ->where('pt.device_type', 'worker_mobile_customer')
            ->where('t.is_active', true)
            ->select('t.id', 't.name', 't.branch_id', 't.table_section_id', 't.receipt_station_id')
            ->orderBy('t.id', 'asc')
            ->get();
    }
}
