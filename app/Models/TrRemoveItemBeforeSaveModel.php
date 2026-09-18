<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Override;

class TrRemoveItemBeforeSaveModel extends Model
{
    use HasUlids;

    protected $table = 'tr_remove_item_before_save';
    protected $guarded = [];
    protected $primaryKey = 'ulid';
    public $timestamps = false;

    #[Override]
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function packages()
    {
        return $this->hasMany(TrRemoveItemBeforeSavePackageModel::class, 'tr_remove_item_before_save_ulid', 'ulid');
    }
}
