<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminalModel extends Model
{
    //
    protected $table='mr_terminal';
    protected $guarded = [];
    // mr_terminal tidak punya kolom created_at/updated_at (lihat migration master_data.php),
    // matikan auto-timestamp bawaan Eloquent supaya update() gak coba isi kolom yang gak ada.
    public $timestamps = false;
}
