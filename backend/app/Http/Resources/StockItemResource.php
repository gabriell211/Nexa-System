<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id'=>$this->id,'sku'=>$this->sku,'name'=>$this->name,'category'=>$this->category,
            'unit'=>$this->unit,'active'=>$this->active,
            'brand'=>$this->brand,'color'=>$this->color,
            'price_cents'=>$this->price_cents,'cost_cents'=>$this->cost_cents,
            'yield_pages'=>$this->yield_pages];
    }
}
