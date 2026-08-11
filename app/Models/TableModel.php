<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TableModel extends Model
{
    //
    public $timestamps = false;
    protected $table="mr_table";
    protected $guarded = [];
}
