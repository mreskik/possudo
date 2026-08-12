<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterImageListModel extends Model
{
    public $timestamps = false;
    protected $table = "mr_image_list";
    protected $guarded = [];
}
