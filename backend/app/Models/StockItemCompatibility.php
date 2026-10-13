<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StockItemCompatibility extends Model
{
    protected $fillable = ['tenant_id','item_id','printer_manufacturer','printer_model','created_by'];
}
