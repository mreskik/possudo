<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterItemModel extends Model
{
    //
    protected $table = 'mr_item';
    protected $guarded = [];
    protected $casts = [
        'flag_soldout' => 'boolean'
    ];
    public $timestamps = false;
}
