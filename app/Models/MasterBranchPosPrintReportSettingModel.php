<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterBranchPosPrintReportSettingModel extends Model
{
    public $timestamps = false;
    protected $table = "mr_branch_pos_print_report_setting";
    protected $primaryKey = "branch_id";
    public $incrementing = false;
    protected $guarded = [];
}
