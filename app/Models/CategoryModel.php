<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryModel extends Model
{
    //
    public $timestamps = false;
    protected $table = 'mr_category';
    protected $guarded = [];
}
