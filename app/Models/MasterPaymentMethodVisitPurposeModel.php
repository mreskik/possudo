<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterPaymentMethodVisitPurposeModel extends Model
{
    //
    public $timestamps = false;
    protected $table = "mr_payment_method_visit_purposes";
    protected $guarded = []; 
}
