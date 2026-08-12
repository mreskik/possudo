<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KioskPaymentRequestModel extends Model
{
    public $timestamps = true;
    protected $table = "tr_kiosk_payment_request";
    protected $primaryKey = "order_id";
    protected $keyType = "string";
    public $incrementing = false;
    protected $guarded = [];
}
