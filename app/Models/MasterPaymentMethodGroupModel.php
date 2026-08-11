<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterPaymentMethodGroupModel extends Model
{
    //
    public $timestamps = false;
    protected $table = "mr_payment_method_group";
    protected $guarded = [];
}
