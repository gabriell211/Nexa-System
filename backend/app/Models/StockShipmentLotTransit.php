<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StockShipmentLotTransit extends Model
{
    protected $table = 'stock_shipment_lot_transit';
    protected $fillable = [
        'tenant_id', 'shipment_id', 'lot_id', 'shipped_quantity', 'received_quantity', 'lost_quantity',
    ];
    protected function casts(): array
    {
        return [
            'shipped_quantity' => 'integer', 'received_quantity' => 'integer', 'lost_quantity' => 'integer',
        ];
    }
}
