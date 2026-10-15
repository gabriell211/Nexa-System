<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockReceiptBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'request_id' => $this->request_id,
            'supplier_id' => (int) $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', static fn () => $this->supplier?->only(['id', 'code', 'name'])),
            'invoice_reference' => $this->invoice_reference,
            'line_count' => (int) $this->line_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(
                static fn ($line): array => [
                    'line_number' => (int) $line->line_number,
                    'receipt' => new StockReceiptResource($line->receipt),
                ]
            )),
        ];
    }
}
