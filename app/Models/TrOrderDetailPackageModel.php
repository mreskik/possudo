<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TrOrderDetailPackageModel extends Model
{
    use HasUlids;
    //
    protected $table = 'tr_order_detail_package';
    protected $guarded = [];
}
