<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockMovement;
use App\Models\StockReceiptDetail;
use App\Models\StockSupplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockDocumentedReceiptService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly AuditWriter $audit,
        private readonly StockLotLedgerService $lots,
    ) {}

    public function create(Tenant $tenant, User $actor, array $input): StockReceiptDetail
    {
        return DB::transaction(function () use ($tenant,$actor,$input): StockReceiptDetail {
            $supplier = StockSupplier::query()->where('tenant_id',$tenant->id)
                ->where('active',true)->find($input['supplier_id']);
            if (!$supplier) {
                throw ValidationException::withMessages(['supplier_id'=>'Fornecedor inativo ou não pertence à empresa.']);
            }
            $expiresOn = $input['expires_on'] ?? null;
            if ($expiresOn !== null && $expiresOn < now('UTC')->toDateString()) {
                throw ValidationException::withMessages(['expires_on'=>'Não é permitido registrar lote já vencido.']);
            }
            $movement = $this->ledger->move($tenant,$actor,[
                'request_id'=>$input['request_id'],
                'kind'=>'receipt','item_id'=>$input['item_id'],
                'source_location_id'=>null,
                'destination_location_id'=>$input['destination_location_id'],
                'quantity'=>$input['quantity'],'reason'=>$input['reason'],
            ]);
            $existing = StockReceiptDetail::query()->where('tenant_id',$tenant->id)
                ->where('movement_id',$movement->id)->first();
            $attributes = [
                'supplier_id'=>(int)$supplier->id,
                'invoice_reference'=>$input['invoice_reference'],
                'lot_number'=>$input['lot_number'],
                'expires_on'=>$expiresOn,
                'unit_cost_cents'=>(int)$input['unit_cost_cents'],
            ];
            if ($existing) {
                foreach ($attributes as $field=>$value) {
                    $actual=$field==='expires_on'
                        ? $existing->expires_on?->toDateString()
                        : $existing->{$field};
                    if ($actual !== $value) {
                        throw ValidationException::withMessages([
                            'request_id'=>'Recebimento repetido com documento, lote ou custo diferente.',
                        ]);
                    }
                }
                return $existing->load(['supplier','movement.item','movement.destination']);
            }

            // Never attach document metadata retroactively to a receipt already
            // committed by the legacy endpoint with the same request UUID.
            if (!$movement->wasRecentlyCreated) {
                throw ValidationException::withMessages([
                    'request_id'=>'Identificador de movimentação existente sem documento.',
                ]);
            }

            $detail=StockReceiptDetail::query()->create([
                'tenant_id'=>$tenant->id,'movement_id'=>$movement->id,...$attributes,
            ]);
            $this->lots->registerReceipt($detail, $movement);
            $this->audit->write($tenant,$actor,'stock.receipt.documented',$detail,null,[
                ...$attributes,'movement_id'=>$movement->id,
                'item_id'=>$movement->item_id,'quantity'=>$movement->quantity,
            ]);
            return $detail->load(['supplier','movement.item','movement.destination']);
        }, 3);
    }
}
