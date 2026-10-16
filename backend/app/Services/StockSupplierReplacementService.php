<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockSupplierReturn;
use App\Models\StockSupplierReplacementCase;
use App\Models\StockSupplierReplacementEvent;
use App\Models\StockSupplierReplacementReceipt;
use App\Models\StockPurchaseReceiptMatch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockSupplierReplacementService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function find(int $tenantId, int $id): StockSupplierReplacementCase
    {
        return StockSupplierReplacementCase::query()->where('tenant_id', $tenantId)
            ->with(['creator:id,name', 'assignee:id,name', 'supplierReturn.purchaseMatch',
                'events' => static fn ($q) => $q->orderBy('id'), 'events.actor:id,name',
                'replacementReceipts' => static fn ($q) => $q->orderBy('id'),
                'replacementReceipts.creator:id,name',
                'replacementReceipts.purchaseMatch.receipt'])
            ->findOrFail($id);
    }

    /** @return array{StockSupplierReplacementCase,bool} */
    public function create(Tenant $tenant, User $actor, array $data): array
    {
        $note = trim($data['note']);
        $hash = $this->hash([
            (int)$actor->id, (int)$data['supplier_return_id'], (int)$data['assigned_user_id'],
            $data['due_on'], $note,
        ]);
        return DB::transaction(function () use ($tenant, $actor, $data, $note, $hash): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = StockSupplierReplacementCase::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if (!hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'UUID já utilizado com outra solicitação.']);
                }
                return [$this->find((int)$tenant->id, (int)$existing->id), false];
            }
            $returned = StockSupplierReturn::query()->where('tenant_id', $tenant->id)
                ->findOrFail($data['supplier_return_id']);
            if ($returned->status !== 'completed') {
                abort(409, 'A devolução ainda não foi concluída.');
            }
            if (StockSupplierReplacementCase::query()->where('tenant_id', $tenant->id)
                ->where('supplier_return_id', $returned->id)->exists()) {
                throw ValidationException::withMessages([
                    'supplier_return_id' => 'Já existe acompanhamento desta devolução.',
                ]);
            }
            $assigned = DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')
                ->where('tenant_user.tenant_id', $tenant->id)
                ->where('tenant_user.user_id', $data['assigned_user_id'])
                ->where('users.active', true)->exists();
            if (!$assigned) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Responsável não pertence à empresa ou está inativo.']);
            }
            $case = StockSupplierReplacementCase::query()->create([
                'tenant_id' => $tenant->id, 'supplier_return_id' => $returned->id,
                'request_id' => $data['request_id'], 'request_hash' => $hash,
                'created_by_user_id' => $actor->id, 'assigned_user_id' => $data['assigned_user_id'],
                'due_on' => $data['due_on'], 'status' => 'awaiting_supplier', 'version' => 1,
            ]);
            $this->event($tenant, $actor, $case, $data['request_id'], $hash, 'opened', $note, null, $data['due_on']);
            $this->audit->write($tenant, $actor, 'stock.supplier_replacement.opened', $case, null, [
                'return_id' => $returned->id, 'assignee' => $data['assigned_user_id'],
                'due_on' => $data['due_on'], 'note' => $note,
            ]);
            return [$this->find((int)$tenant->id, (int)$case->id), true];
        }, 3);
    }

    /** @return array{StockSupplierReplacementCase,bool} */
    public function record(Tenant $tenant, User $actor, int $id, string $role, array $data): array
    {
        $note = trim($data['note']);
        $action = $data['action'];
        $reference = trim((string)($data['supplier_reference'] ?? ''));
        $reference = $reference === '' ? null : $reference;
        $due = $data['due_on'] ?? null;
        if ($action !== 'supplier_confirmed' && $reference !== null) {
            throw ValidationException::withMessages(['supplier_reference'=>'Referência válida apenas para confirmação do fornecedor.']);
        }
        if ($action !== 'rescheduled' && $due !== null) {
            throw ValidationException::withMessages(['due_on'=>'Prazo válido apenas para reagendamento.']);
        }
        $hash = $this->hash([(int)$actor->id,$id,$action,$note,$reference,$due]);
        return DB::transaction(function () use ($tenant,$actor,$id,$role,$data,$hash,$action,$note,$reference,$due): array {
            $case = StockSupplierReplacementCase::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($id);
            $prior = StockSupplierReplacementEvent::query()->where('tenant_id', $tenant->id)
                ->where('request_id', $data['request_id'])->first();
            if ($prior) {
                if ((int)$prior->replacement_case_id !== $id || !hash_equals($prior->request_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id'=>'UUID de evento já utilizado.']);
                }
                return [$this->find((int)$tenant->id, $id), false];
            }
            if ((int)$case->version !== (int)$data['expected_version'] || $case->status !== 'awaiting_supplier') {
                abort(409, 'O acompanhamento foi modificado ou já está encerrado.');
            }
            $manager = in_array($role, ['owner','admin','manager'], true);
            if ($action !== 'follow_up' && !$manager) {
                abort(403, 'Apenas a gestão confirma, reagenda ou encerra.');
            }
            if (in_array($action, ['supplier_confirmed','closed_unresolved'], true)
                && (int)$case->created_by_user_id === (int)$actor->id) {
                abort(409, 'Outra pessoa da gestão deve confirmar ou encerrar o acompanhamento.');
            }
            if ($action === 'supplier_confirmed' && $reference === null) {
                throw ValidationException::withMessages(['supplier_reference'=>'Informe referência documental do fornecedor.']);
            }
            $before = ['status'=>$case->status,'version'=>$case->version,'due_on'=>$case->due_on?->format('Y-m-d')];
            $changes = ['version'=>$case->version + 1];
            if ($action === 'rescheduled') {
                $changes['due_on'] = $due;
            } elseif ($action === 'supplier_confirmed') {
                $changes += ['status'=>'supplier_confirmed','supplier_reference'=>$reference,'supplier_confirmed_at'=>now()];
            } elseif ($action === 'closed_unresolved') {
                $changes['status'] = 'closed_unresolved';
            }
            $case->forceFill($changes)->save();
            $this->event($tenant,$actor,$case,$data['request_id'],$hash,$action,$note,$reference,$due);
            $this->audit->write($tenant,$actor,'stock.supplier_replacement.'.$action,$case,$before,[
                'status'=>$case->status,'version'=>$case->version,'due_on'=>$case->due_on?->format('Y-m-d'),
                'reference'=>$reference,'note'=>$note,
            ]);
            return [$this->find((int)$tenant->id, $id), true];
        }, 3);
    }

    /** @return array{StockSupplierReplacementCase,bool} */
    public function linkReceipt(Tenant $tenant, User $actor, int $id, array $data): array
    {
        $note = trim($data['note']);
        $matchId = (int) $data['purchase_receipt_match_id'];
        $quantity = (int) $data['quantity'];
        $hash = $this->hash([(int) $actor->id, $id, $matchId, $quantity, $note]);

        return DB::transaction(function () use ($tenant, $actor, $id, $data, $note, $matchId, $quantity, $hash): array {
            $tenantId = (int) $tenant->id;
            // Every allocation of a matched receipt takes this lock, including allocations
            // to different replacement cases. The case lock serializes its own quota.
            $match = StockPurchaseReceiptMatch::query()->where('tenant_id', $tenantId)
                ->with('receipt')->lockForUpdate()->findOrFail($matchId);
            $case = StockSupplierReplacementCase::query()->where('tenant_id', $tenantId)
                ->with('supplierReturn.purchaseMatch')->lockForUpdate()->findOrFail($id);
            $prior = StockSupplierReplacementReceipt::query()->where('tenant_id', $tenantId)
                ->where('request_id', $data['request_id'])->first();
            if ($prior) {
                if ((int) $prior->replacement_case_id !== $id || !hash_equals($prior->request_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'UUID já utilizado com outra vinculação.']);
                }
                return [$this->find($tenantId, $id), false];
            }
            if ((int) $case->version !== (int) $data['expected_version']
                || in_array($case->status, ['closed_unresolved', 'receipt_documented'], true)) {
                abort(409, 'O acompanhamento foi modificado ou já está encerrado.');
            }
            $returned = $case->supplierReturn;
            $original = $returned->purchaseMatch;
            if ($returned->status !== 'completed'
                || (int) $original->purchase_order_id !== (int) $match->purchase_order_id
                || (int) $original->purchase_order_line_id !== (int) $match->purchase_order_line_id
                || (int) $original->id === (int) $match->id
                || $match->receipt === null
                || $returned->reviewed_at === null
                || $match->receipt->created_at->lt($returned->reviewed_at)) {
                throw ValidationException::withMessages([
                    'purchase_receipt_match_id' => 'Selecione uma entrada posterior à devolução, conciliada com o mesmo pedido e material.',
                ]);
            }
            if (StockSupplierReplacementReceipt::query()->where('tenant_id', $tenantId)
                ->where('replacement_case_id', $id)->where('purchase_receipt_match_id', $matchId)->exists()) {
                throw ValidationException::withMessages([
                    'purchase_receipt_match_id' => 'Esta entrada já foi vinculada ao acompanhamento.',
                ]);
            }
            $caseQuantity = (int) $case->replacementReceipts()->sum('quantity');
            $matchQuantity = (int) StockSupplierReplacementReceipt::query()->where('tenant_id', $tenantId)
                ->where('purchase_receipt_match_id', $matchId)->sum('quantity');
            if ($caseQuantity + $quantity > (int) $returned->quantity
                || $matchQuantity + $quantity > (int) $match->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'A quantidade excede a devolução ou a entrada ainda não vinculada.',
                ]);
            }
            $case->replacementReceipts()->create([
                'tenant_id' => $tenantId, 'purchase_receipt_match_id' => $matchId,
                'quantity' => $quantity, 'request_id' => $data['request_id'],
                'request_hash' => $hash, 'note' => $note, 'created_by_user_id' => $actor->id,
            ]);
            $before = ['status' => $case->status, 'version' => $case->version,
                'documented_quantity' => $caseQuantity];
            $case->forceFill([
                'version' => $case->version + 1,
                'status' => $caseQuantity + $quantity === (int) $returned->quantity
                    ? 'receipt_documented' : $case->status,
            ])->save();
            $this->audit->write($tenant, $actor, 'stock.supplier_replacement.receipt_linked', $case,
                $before, ['status' => $case->status, 'version' => $case->version,
                    'documented_quantity' => $caseQuantity + $quantity,
                    'purchase_receipt_match_id' => $matchId, 'quantity' => $quantity,
                    'invoice_reference' => $match->receipt->invoice_reference,
                    'lot_number' => $match->receipt->lot_number, 'note' => $note]);
            return [$this->find($tenantId, $id), true];
        }, 3);
    }

    private function event(Tenant $tenant, User $actor, StockSupplierReplacementCase $case,
        string $requestId, string $hash, string $action, string $note,
        ?string $reference, ?string $due): void
    {
        $case->events()->create([
            'tenant_id'=>$tenant->id,'actor_user_id'=>$actor->id,'request_id'=>$requestId,
            'request_hash'=>$hash,'action'=>$action,'note'=>$note,
            'supplier_reference'=>$reference,'due_on'=>$due,
        ]);
    }

    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
