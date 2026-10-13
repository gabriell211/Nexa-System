<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id'=>$this->id,'name'=>$this->name,'kind'=>$this->kind,
            'customer_id'=>$this->customer_id,'customer_location_id'=>$this->customer_location_id,
            'technician_user_id'=>$this->technician_user_id,'active'=>$this->active];
    }
}
