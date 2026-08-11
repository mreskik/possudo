<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterMemberModel extends Model
{
    //
    public $timestamps = false;
    protected $table = 'mr_member';
    protected $guarded = [];
}
