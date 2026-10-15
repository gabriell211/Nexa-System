<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockSupplierReplacementCase extends Model
{
    protected $fillable = [
        'tenant_id','supplier_return_id','request_id','request_hash','assigned_user_id',
        'created_by_user_id','due_on','status','version','supplier_reference','supplier_confirmed_at',
    ];
    protected $hidden = ['request_hash'];
    protected function casts(): array
    {
        return ['due_on'=>'date:Y-m-d','supplier_confirmed_at'=>'datetime','version'=>'integer'];
    }
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(StockSupplierReturn::class, 'supplier_return_id');
    }
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
    public function events(): HasMany
    {
        return $this->hasMany(StockSupplierReplacementEvent::class, 'replacement_case_id');
    }
}
