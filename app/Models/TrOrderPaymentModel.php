<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TrOrderPaymentModel extends Model
{
    //
    use HasUlids;

    protected $table = "tr_order_payment";
    protected $guarded = [];
    protected $primaryKey = 'ulid';
}
