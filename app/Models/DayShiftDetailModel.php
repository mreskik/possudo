<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DayShiftDetailModel extends Model
{
    //
    use HasUlids;

    public $timestamps = false;
    protected $table = 'tr_dayshift_detail';
    protected $guarded = [];
    protected $primaryKey = 'ulid';
}
