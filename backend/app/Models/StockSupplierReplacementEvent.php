<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockSupplierReplacementEvent extends Model
{
    protected $fillable = [
        'tenant_id','replacement_case_id','request_id','request_hash','action','note',
        'supplier_reference','due_on','actor_user_id',
    ];
    protected $hidden = ['request_hash'];
    protected function casts(): array
    {
        return ['due_on'=>'date:Y-m-d'];
    }
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
