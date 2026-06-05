<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Override;

class TrOrderDetailModel extends Model
{
    //
    use HasUlids;
    protected $table = "tr_order_detail";
    protected $guarded = [];

    protected $primaryKey = 'ulid';
    #[Override]
    public function uniqueIds(): array
    {
        return ['ulid'];
    }
}
