<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterImageModel extends Model
{
    public $timestamps = false;
    protected $table = "mr_image";
    protected $guarded = [];
}
