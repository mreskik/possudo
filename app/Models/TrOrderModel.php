<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrOrderModel extends Model
{
    //
    protected $table = 'tr_order';
    protected $guarded = [];

    // flag_inclusive_tax wajib di-cast ke bool asli -- tanpa ini Eloquent balikin raw MySQL
    // tinyint (0/1) dan json_encode ngerender angka, bukan boolean literal (true/false).
    // Struct Go di APIANDORDER (PosOrderModel.FlagInclusiveTax *bool) nolak unmarshal angka
    // ke field bool, jadi push order/pos_order bisa gagal total kalau ini gak di-cast.
    protected $casts = [
        'flag_inclusive_tax' => 'boolean',
    ];
}
