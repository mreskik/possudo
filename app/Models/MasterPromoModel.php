<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterPromoModel extends Model
{
    //
    public $timestamps = false;
    protected $table = 'mr_promo';
    protected $guarded = [];

    // tiers (2026-09-30) -- dipakai OrderServices::ValidatePromoTierBarrier().
    public function tiers()
    {
        return $this->hasMany(MasterPromoTierModel::class, 'promo_id');
    }
}
