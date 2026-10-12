<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterBranchSettingModel extends Model
{
    public $timestamps = false;
    protected $table = "mr_branch_setting";
    protected $primaryKey = "branch_id";
    public $incrementing = false;
    protected $guarded = [];
}
