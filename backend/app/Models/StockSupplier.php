<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StockSupplier extends Model
{
    protected $fillable = ['tenant_id','code','name','email','document','active'];
    protected function casts(): array { return ['active'=>'boolean']; }
}
