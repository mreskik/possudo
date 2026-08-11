<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterPaymentMethodModel extends Model
{
    //
    public $timestamps = false;
    protected $table = 'mr_payment_method';
    protected $guarded = [];
}
