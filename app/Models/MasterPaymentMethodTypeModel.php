<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterPaymentMethodTypeModel extends Model
{
    //
    public $timestamps = false;
    protected $table = "mr_payment_method_type";
    protected $guarded = [];
}
