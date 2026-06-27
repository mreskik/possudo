<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DaySiftModel extends Model
{
    //
    use HasUlids;

    protected $table = 'tr_dayshift';
    protected $guarded = [];
    public $timestamps = false;
    protected $primaryKey = 'ulid';
}
