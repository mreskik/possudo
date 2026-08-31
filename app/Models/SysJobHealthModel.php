<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SysJobHealthModel extends Model
{
    protected $table = 'sys_job_health';
    protected $primaryKey = 'job_name';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'last_tick_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];
}
