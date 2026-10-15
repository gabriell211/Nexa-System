<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StockPurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'Purchasing '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'password' => 'test-password-value',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return [$user, $user->createToken('test', ['api','tenant:'.$tenant->id])->plainTextToken];
    }

    private function as(string $token): static
    {
        Auth::forgetGuards();
        return $this->withToken($token);
    }

    private function fixture(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Purchase Provider', 'slug' => 'purchase-'.Str::lower(Str::random(8)),
        ]);
        [$creator, $creatorToken] = $this->token($tenant, 'warehouse');
        [$manager, $managerToken] = $this->token($tenant, 'manager');
        $item = $this->as($creatorToken)->postJson('/api/v1/stock/items', [
            'sku' => 'PO-'.Str::upper(Str::random(8)), 'name' => 'Toner', 'category' => 'toner',
        ])->assertCreated()->json('data.id');
        $source = $this->as($creatorToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Source purchasing', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $destination = $this->as($creatorToken)->postJson('/api/v1/stock/locations', [
            'name' => 'Target purchasing', 'kind' => 'provider',
        ])->assertCreated()->json('data.id');
        $policy = $this->as($creatorToken)->putJson(
            "/api/v1/stock/reorder-policies/{$item}/{$destination}", [
                'critical_quantity' => 1, 'safety_quantity' => 2,
                'minimum_quantity' => 3, 'target_quantity' => 8,
            ]
        )->assertCreated()->json('data.id');
        $planResponse = $this->as($creatorToken)->postJson('/api/v1/stock/replenishment-plans', [
            'request_id' => (string) Str::uuid(),
            'due_on' => now('UTC')->addDays(5)->toDateString(),
            'responsible_user_id' => $creator->id,
            'policy_ids' => [$policy],
        ])->assertCreated();
        $plan = $planResponse->json('data.id');
        $line = $planResponse->json('data.items.0.id');
        $this->as($managerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/approve", [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $supplier = $this->as($creatorToken)->postJson('/api/v1/stock/suppliers', [
            'code' => 'PURCHASE-SUP', 'name' => 'Supplier of supplies',
        ])->assertCreated()->json('data.id');
        return compact('tenant','creator','creatorToken','manager','managerToken',
            'item','source','destination','plan','line','supplier');
    }

    private function payload(int $plan, int $supplier, int $line, int $quantity = 5): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'plan_id' => $plan, 'supplier_id' => $supplier,
            'notes' => 'Solicitação de compra para suprimentos',
            'items' => [['plan_line_id' => $line, 'quantity' => $quantity, 'unit_cost_cents' => 6875]],
        ];
    }

    public function test_purchase_requires_second_human_approval_and_never_moves_inventory(): void
    {
        extract($this->fixture());
        $payload = $this->payload($plan, $supplier, $line);
        $created = $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.total_cents', 34375)
            ->assertJsonPath('data.items.0.plan_line_id', $line)
            ->assertJsonPath('data.items.0.destination.id', $destination);
        $order = $created->json('data.id');
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', $payload)
            ->assertOk()->assertJsonPath('data.id', $order);
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', [
            ...$payload, 'items' => [['plan_line_id' => $line, 'quantity' => 4, 'unit_cost_cents' => 6875]],
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertForbidden();
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1, 'review_note' => 'Proposta conferida pelo comprador',
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.approved_by.id', $manager->id);
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertStatus(409);
        $this->as($creatorToken)->getJson("/api/v1/stock/purchase-orders/{$order}")
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.supplier.code', 'PURCHASE-SUP');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_shipments', 0);
        $this->assertDatabaseCount('stock_receipt_details', 0);
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id, 'action' => 'stock.purchase_order.approved',
            'entity_type' => 'StockPurchaseOrder', 'entity_id' => $order,
        ]);
        $this->as($managerToken)->postJson("/api/v1/stock/replenishment-plans/{$plan}/cancel", [
            'expected_version' => 2, 'reason' => 'Não cancelar compras abertas',
        ])->assertStatus(409);
    }

    public function test_approved_purchase_and_shipments_share_one_plan_quota(): void
    {
        extract($this->fixture());
        $this->as($creatorToken)->postJson('/api/v1/stock/movements', [
            'request_id' => (string) Str::uuid(), 'kind' => 'receipt',
            'item_id' => $item, 'source_location_id' => null,
            'destination_location_id' => $source, 'quantity' => 20,
            'reason' => 'Estoque físico disponível na origem',
        ])->assertCreated();
        $order = $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders',
            $this->payload($plan, $supplier, $line, 5))
            ->assertCreated()->json('data.id');
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertOk();
        $shipmentEndpoint = "/api/v1/stock/replenishment-plans/{$plan}/items/{$line}/reserve-shipment";
        $this->as($creatorToken)->postJson($shipmentEndpoint, [
            'request_id' => (string) Str::uuid(), 'source_location_id' => $source,
            'quantity' => 4, 'reason' => 'Separação manual para destino',
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson($shipmentEndpoint, [
            'request_id' => (string) Str::uuid(), 'source_location_id' => $source,
            'quantity' => 3, 'reason' => 'Separação manual para destino',
        ])->assertCreated();
        $second = $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders',
            $this->payload($plan, $supplier, $line, 1))
            ->assertCreated()->json('data.id');
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$second}/approve", [
            'expected_version' => 1,
        ])->assertUnprocessable();
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/cancel", [
            'expected_version' => 2, 'reason' => 'Compra cancelada pelo gestor',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$second}/approve", [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($creatorToken)->getJson('/api/v1/stock/purchase-orders?status=approved')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $second);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_inactive_supplier_duplicate_line_and_foreign_tenant_are_rejected(): void
    {
        extract($this->fixture());
        $original = $this->payload($plan, $supplier, $line, 2);
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', [
            ...$original, 'items' => [$original['items'][0], $original['items'][0]],
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', [
            ...$original, 'items' => [],
        ])->assertUnprocessable();
        $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', [
            ...$original, 'items' => [['plan_line_id' => $line, 'quantity' => 1, 'unit_cost_cents' => -1]],
        ])->assertUnprocessable();
        $order = $this->as($creatorToken)->postJson('/api/v1/stock/purchase-orders', $original)
            ->assertCreated()->json('data.id');
        $this->as($creatorToken)->patchJson("/api/v1/stock/suppliers/{$supplier}", [
            'active' => false,
        ])->assertOk();
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('stock_purchase_orders', ['id' => $order, 'status' => 'draft']);
        $other = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'purchase-other']);
        [, $foreign] = $this->token($other);
        $this->as($foreign)->getJson("/api/v1/stock/purchase-orders/{$order}")->assertNotFound();
        $this->as($foreign)->getJson('/api/v1/stock/purchase-orders')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->as($foreign)->postJson('/api/v1/stock/purchase-orders', $original)->assertNotFound();
        [, $technician] = $this->token($tenant, 'technician');
        $this->as($technician)->getJson('/api/v1/stock/purchase-orders')->assertForbidden();
        $this->as($technician)->postJson('/api/v1/stock/purchase-orders', $original)->assertForbidden();
        $this->as($creatorToken)->postJson("/api/v1/stock/purchase-orders/{$order}/cancel", [
            'expected_version' => 1, 'reason' => 'Cancelar rascunho pelo autor',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('stock_purchase_order_lines', 1);
        $this->assertDatabaseCount('stock_receipt_details', 0);
    }

    public function test_manager_cannot_approve_own_purchase_and_filtering_is_scoped(): void
    {
        extract($this->fixture());
        $payload = $this->payload($plan, $supplier, $line, 2);
        $order = $this->as($managerToken)->postJson('/api/v1/stock/purchase-orders', $payload)
            ->assertCreated()->json('data.id');
        $this->as($managerToken)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertStatus(409);
        [, $owner] = $this->token($tenant, 'owner');
        $this->as($owner)->postJson("/api/v1/stock/purchase-orders/{$order}/approve", [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($creatorToken)->getJson("/api/v1/stock/purchase-orders?plan_id={$plan}&supplier_id={$supplier}&per_page=1")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.total_cents', 13750);
        $this->as($creatorToken)->getJson('/api/v1/stock/purchase-orders?per_page=101')
            ->assertUnprocessable();
        $this->as($creatorToken)->getJson('/api/v1/stock/purchase-orders?status=wrong')
            ->assertUnprocessable();
    }

    private function approvedPurchase(array $fixture, int $quantity = 3): array
    {
        $payload = $this->payload($fixture['plan'], $fixture['supplier'], $fixture['line'], $quantity);
        $created = $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/purchase-orders', $payload)
            ->assertCreated();
        $orderId = (int) $created->json('data.id');
        $lineId = (int) $created->json('data.items.0.id');
        $this->as($fixture['managerToken'])->postJson("/api/v1/stock/purchase-orders/{$orderId}/approve", [
            'expected_version' => 1,
        ])->assertOk();
        return [$orderId, $lineId];
    }

    private function documentedPurchaseReceipt(array $fixture, int $quantity, int $cost, string $invoice): int
    {
        return (int) $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/receipts', [
            'request_id' => (string) Str::uuid(),
            'supplier_id' => $fixture['supplier'],
            'item_id' => $fixture['item'],
            'destination_location_id' => $fixture['destination'],
            'quantity' => $quantity, 'invoice_reference' => $invoice,
            'lot_number' => 'LOTE-'.$invoice, 'unit_cost_cents' => $cost,
            'reason' => 'Recebimento físico conferido no depósito',
        ])->assertCreated()->json('data.id');
    }

    public function test_purchase_receipt_matching_tracks_partial_full_cost_variance_and_is_idempotent(): void
    {
        $fixture = $this->fixture();
        [$order, $line] = $this->approvedPurchase($fixture, 3);
        $uri = "/api/v1/stock/purchase-orders/{$order}/reconcile-receipt";
        $first = $this->documentedPurchaseReceipt($fixture, 1, 6875, 'NF-COMPRA-A');
        $second = $this->documentedPurchaseReceipt($fixture, 2, 7100, 'NF-COMPRA-B');
        $this->assertDatabaseCount('stock_movements', 2);

        $payload = ['purchase_order_line_id' => $line, 'receipt_detail_id' => $first];
        $this->as($fixture['creatorToken'])->postJson($uri, $payload)
            ->assertCreated()->assertJsonPath('data.items.0.received_quantity', 1)
            ->assertJsonPath('data.items.0.outstanding_quantity', 2)
            ->assertJsonPath('data.items.0.cost_divergence', false)
            ->assertJsonPath('data.items.0.receipts.0.invoice_reference', 'NF-COMPRA-A');
        $this->as($fixture['creatorToken'])->postJson($uri, $payload)
            ->assertOk()->assertJsonPath('data.items.0.received_quantity', 1);
        $this->as($fixture['creatorToken'])->postJson($uri, [
            'purchase_order_line_id' => $line, 'receipt_detail_id' => $second,
        ])->assertUnprocessable()->assertJsonValidationErrors('variance_reason');
        $this->as($fixture['creatorToken'])->postJson($uri, [
            'purchase_order_line_id' => $line, 'receipt_detail_id' => $second,
            'variance_reason' => 'Valor da nota difere da cotação original',
        ])->assertCreated()->assertJsonPath('data.items.0.received_quantity', 3)
            ->assertJsonPath('data.items.0.outstanding_quantity', 0)
            ->assertJsonPath('data.items.0.cost_divergence', true)
            ->assertJsonPath('data.items.0.receipts.1.unit_cost_cents', 7100);
        $this->assertDatabaseCount('stock_purchase_receipt_matches', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame(2, DB::table('audit_entries')
            ->where('action', 'stock.purchase_order.receipt_matched')->count());
        $this->as($fixture['managerToken'])->postJson("/api/v1/stock/purchase-orders/{$order}/cancel", [
            'expected_version' => 2, 'reason' => 'Impossível cancelar com recebimentos vinculados',
        ])->assertStatus(409);

        $this->as($fixture['creatorToken'])->getJson('/api/v1/stock/receipts?unmatched_only=1&supplier_id='.$fixture['supplier'])
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->as($fixture['creatorToken'])->getJson('/api/v1/stock/receipts?q=compra-b')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_receipt_matching_rejects_oversupply_wrong_supplier_destination_and_foreign_tenants(): void
    {
        $fixture = $this->fixture();
        [$order, $line] = $this->approvedPurchase($fixture, 2);
        $uri = "/api/v1/stock/purchase-orders/{$order}/reconcile-receipt";
        $valid = $this->documentedPurchaseReceipt($fixture, 1, 6875, 'NF-OK');
        $oversupply = $this->documentedPurchaseReceipt($fixture, 2, 6875, 'NF-EXCESSO');
        $this->as($fixture['creatorToken'])->postJson($uri, [
            'purchase_order_line_id' => $line, 'receipt_detail_id' => $valid,
        ])->assertCreated();
        $this->as($fixture['creatorToken'])->postJson($uri, [
            'purchase_order_line_id' => $line, 'receipt_detail_id' => $oversupply,
        ])->assertUnprocessable()->assertJsonValidationErrors('receipt_detail_id');

        $wrongSupplier = $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/suppliers', [
            'code' => 'WRONG-SUPPLIER', 'name' => 'Outro fornecedor',
        ])->assertCreated()->json('data.id');
        $wrong = $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/receipts', [
            'request_id' => (string) Str::uuid(), 'supplier_id' => $wrongSupplier,
            'item_id' => $fixture['item'], 'destination_location_id' => $fixture['destination'],
            'quantity' => 1, 'invoice_reference' => 'NF-ERRADA',
            'lot_number' => 'LOTE-ERRADO', 'unit_cost_cents' => 6875,
            'reason' => 'Recebimento válido de outro fornecedor',
        ])->assertCreated()->json('data.id');
        $this->as($fixture['creatorToken'])->postJson($uri, [
            'purchase_order_line_id' => $line, 'receipt_detail_id' => $wrong,
        ])->assertUnprocessable();

        $anotherTenant = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'match-foreign']);
        [, $foreign] = $this->token($anotherTenant);
        $this->as($foreign)->postJson($uri, [
            'purchase_order_line_id' => $line, 'receipt_detail_id' => $valid,
        ])->assertNotFound();
        $this->as($foreign)->getJson("/api/v1/stock/purchase-orders/{$order}")->assertNotFound();
        [, $technician] = $this->token($fixture['tenant'], 'technician');
        $this->as($technician)->postJson($uri, [
            'purchase_order_line_id' => $line, 'receipt_detail_id' => $valid,
        ])->assertForbidden();

        $this->as($fixture['creatorToken'])->postJson($uri, [
            'purchase_order_line_id' => $line, 'receipt_detail_id' => $valid,
            'variance_reason' => 'Reuso com conteúdo diferente',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_purchase_receipt_matches', 1);
    }


    private function reconciledForReturn(array $fixture, int $quantity, string $invoice): array
    {
        [$order, $line] = $this->approvedPurchase($fixture, $quantity);
        $receiptId = $this->documentedPurchaseReceipt($fixture, $quantity, 6875, $invoice);
        $this->as($fixture['creatorToken'])->postJson(
            "/api/v1/stock/purchase-orders/{$order}/reconcile-receipt", [
                'purchase_order_line_id' => $line, 'receipt_detail_id' => $receiptId,
            ]
        )->assertCreated();
        $match = (int) DB::table('stock_purchase_receipt_matches')
            ->where('tenant_id', $fixture['tenant']->id)->where('receipt_detail_id', $receiptId)
            ->value('id');
        return [$order, $line, $receiptId, $match];
    }

    public function test_supplier_return_requires_independent_confirmation_and_uses_the_exact_receipt_lot(): void
    {
        $fixture = $this->fixture();
        [$order, $line, $receiptId, $match] = $this->reconciledForReturn($fixture, 5, 'NF-DEVOLUCAO-A');
        $secondReceipt = $this->documentedPurchaseReceipt($fixture, 2, 6875, 'NF-DEVOLUCAO-B');
        $firstLot = (int) DB::table('stock_lots')->where('receipt_detail_id', $receiptId)->value('id');
        $secondLot = (int) DB::table('stock_lots')->where('receipt_detail_id', $secondReceipt)->value('id');
        $this->assertNotSame($firstLot, $secondLot);

        $uuid = (string) Str::uuid();
        $request = [
            'request_id' => $uuid, 'purchase_receipt_match_id' => $match,
            'quantity' => 2, 'reason' => 'Material avariado identificado na conferência',
        ];
        $before = (int) DB::table('stock_movements')->count();
        $submitted = $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/supplier-returns', $request)
            ->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.movement_id', null);
        $returnId = $submitted->json('data.id');
        $this->assertDatabaseCount('stock_movements', $before);
        $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/supplier-returns', $request)
            ->assertOk()->assertJsonPath('data.id', $returnId);
        $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/supplier-returns', [
            ...$request, 'quantity' => 1,
        ])->assertUnprocessable();
        $this->as($fixture['creatorToken'])->postJson("/api/v1/stock/supplier-returns/{$returnId}/approve", [
            'expected_version' => 1,
        ])->assertForbidden();

        $result = $this->as($fixture['managerToken'])->postJson(
            "/api/v1/stock/supplier-returns/{$returnId}/approve",
            ['expected_version' => 1, 'review_note' => 'Lote físico e quantidade conferidos por supervisor']
        )->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.version', 2);
        $movementId = (int) $result->json('data.movement_id');
        $this->assertGreaterThan(0, $movementId);
        $this->assertDatabaseCount('stock_movements', $before + 1);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movementId, 'tenant_id' => $fixture['tenant']->id,
            'kind' => 'issue', 'quantity' => 2,
            'source_location_id' => $fixture['destination'],
        ]);
        $this->assertDatabaseHas('stock_movement_lot_allocations', [
            'tenant_id' => $fixture['tenant']->id, 'movement_id' => $movementId,
            'lot_id' => $firstLot, 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('stock_lot_balances', [
            'lot_id' => $firstLot, 'location_id' => $fixture['destination'], 'quantity' => 3,
        ]);
        $this->assertDatabaseHas('stock_lot_balances', [
            'lot_id' => $secondLot, 'location_id' => $fixture['destination'], 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $fixture['tenant']->id, 'item_id' => $fixture['item'],
            'location_id' => $fixture['destination'], 'quantity' => 5,
        ]);
        $this->as($fixture['managerToken'])->postJson(
            "/api/v1/stock/supplier-returns/{$returnId}/approve",
            ['expected_version' => 1]
        )->assertStatus(409);
        $this->assertDatabaseCount('stock_movements', $before + 1);
        $this->as($fixture['creatorToken'])->getJson("/api/v1/stock/purchase-orders/{$order}")
            ->assertOk()->assertJsonPath('data.items.0.receipts.0.returned_quantity', 2);
        $this->as($fixture['creatorToken'])->getJson("/api/v1/stock/supplier-returns?purchase_order_id={$order}")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.receipt.invoice_reference', 'NF-DEVOLUCAO-A');
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $fixture['tenant']->id,
            'entity_type' => 'StockSupplierReturn',
            'entity_id' => $returnId, 'action' => 'stock.supplier_return.completed',
        ]);
    }

    public function test_return_rejects_overmatched_quantity_and_insufficient_exact_lot_stock(): void
    {
        $fixture = $this->fixture();
        [, , $receiptId, $match] = $this->reconciledForReturn($fixture, 2, 'NF-DEVOLUCAO-QUOTA');
        $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/supplier-returns', [
            'request_id' => (string) Str::uuid(),
            'purchase_receipt_match_id' => $match,
            'quantity' => 3, 'reason' => 'Excede o recebimento conciliado',
        ])->assertUnprocessable();

        $returnId = $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/supplier-returns', [
            'request_id' => (string) Str::uuid(),
            'purchase_receipt_match_id' => $match,
            'quantity' => 2, 'reason' => 'Devolver lote físico ao fornecedor',
        ])->assertCreated()->json('data.id');

        $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/supplier-returns', [
            'request_id' => (string) Str::uuid(),
            'purchase_receipt_match_id' => $match,
            'quantity' => 1, 'reason' => 'Tentativa de reservar mais do que recebido',
        ])->assertUnprocessable();

        // A separate stock issue is legal; the approval must revalidate the lot balance.
        $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/movements', [
            'request_id' => (string) Str::uuid(),
            'kind' => 'issue',
            'item_id' => $fixture['item'],
            'source_location_id' => $fixture['destination'],
            'destination_location_id' => null,
            'quantity' => 1,
            'reason' => 'Consumo anterior à aprovação da devolução',
        ])->assertCreated();
        $this->as($fixture['managerToken'])->postJson(
            "/api/v1/stock/supplier-returns/{$returnId}/approve", ['expected_version' => 1]
        )->assertUnprocessable();
        $this->assertDatabaseHas('stock_supplier_returns', [
            'id' => $returnId, 'status' => 'pending', 'movement_id' => null,
        ]);
        $this->as($fixture['creatorToken'])->postJson(
            "/api/v1/stock/supplier-returns/{$returnId}/cancel", [
                'expected_version' => 1, 'reason' => 'O lote já não possui a quantidade necessária',
            ]
        )->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('stock_supplier_returns', 1);
    }

    public function test_supplier_returns_are_tenant_scoped_and_role_guarded(): void
    {
        $fixture = $this->fixture();
        [, , , $match] = $this->reconciledForReturn($fixture, 1, 'NF-DEVOLUCAO-SCOPE');
        $payload = [
            'request_id' => (string) Str::uuid(),
            'purchase_receipt_match_id' => $match,
            'quantity' => 1, 'reason' => 'Retorno por lote danificado',
        ];
        $other = Tenant::query()->create(['name' => 'Other company', 'slug' => 'supplier-return-foreign']);
        [, $foreign] = $this->token($other);
        $this->as($foreign)->postJson('/api/v1/stock/supplier-returns', $payload)->assertNotFound();
        $this->as($foreign)->getJson('/api/v1/stock/supplier-returns')
            ->assertOk()->assertJsonPath('meta.total', 0);
        [, $technician] = $this->token($fixture['tenant'], 'technician');
        $this->as($technician)->postJson('/api/v1/stock/supplier-returns', $payload)->assertForbidden();

        $returnId = $this->as($fixture['creatorToken'])->postJson('/api/v1/stock/supplier-returns', $payload)
            ->assertCreated()->json('data.id');
        $this->as($foreign)->getJson("/api/v1/stock/supplier-returns/{$returnId}")
            ->assertNotFound();
        $this->as($fixture['creatorToken'])->postJson(
            "/api/v1/stock/supplier-returns/{$returnId}/approve", ['expected_version' => 1]
        )->assertForbidden();
        $this->as($fixture['creatorToken'])->postJson(
            "/api/v1/stock/supplier-returns/{$returnId}/cancel", [
                'expected_version' => 1, 'reason' => 'Solicitação rejeitada por conferência',
            ]
        )->assertOk();
        $this->assertDatabaseCount('stock_movements', 1);
    }


    public function test_completed_return_releases_quota_for_replacement_without_changing_gross_history(): void
    {
        $fixture = $this->fixture();
        [$order, $line, $originalReceipt, $match] =
            $this->reconciledForReturn($fixture, 3, 'NF-REPLACEMENT-ORIGINAL');
        $returnRequest = [
            'request_id' => (string) Str::uuid(),
            'purchase_receipt_match_id' => $match,
            'quantity' => 2,
            'reason' => 'Material com defeito confirmado na inspeção',
        ];
        $return = $this->as($fixture['creatorToken'])
            ->postJson('/api/v1/stock/supplier-returns', $returnRequest)
            ->assertCreated()->json('data.id');
        $otherReceipt = $this->documentedPurchaseReceipt(
            $fixture, 2, 6875, 'NF-REPLACEMENT-ARRIVAL'
        );
        $uri = "/api/v1/stock/purchase-orders/{$order}/reconcile-receipt";
        $attach = ['purchase_order_line_id' => $line, 'receipt_detail_id' => $otherReceipt];

        $this->as($fixture['creatorToken'])->getJson("/api/v1/stock/purchase-orders/{$order}")
            ->assertOk()
            ->assertJsonPath('data.fulfillment.status', 'return_review_pending')
            ->assertJsonPath('data.fulfillment.ordered_quantity', 3)
            ->assertJsonPath('data.fulfillment.gross_received_quantity', 3)
            ->assertJsonPath('data.fulfillment.pending_return_quantity', 2)
            ->assertJsonPath('data.fulfillment.returned_quantity', 0)
            ->assertJsonPath('data.fulfillment.retained_quantity', 3)
            ->assertJsonPath('data.fulfillment.outstanding_quantity', 0)
            ->assertJsonPath('data.items.0.replacement_needed_quantity', 0);
        $this->as($fixture['creatorToken'])->postJson($uri, $attach)
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_detail_id');

        $this->as($fixture['managerToken'])->postJson(
            "/api/v1/stock/supplier-returns/{$return}/approve",
            ['expected_version' => 1, 'review_note' => 'Conferência física da devolução']
        )->assertOk()->assertJsonPath('data.status', 'completed');

        $this->as($fixture['creatorToken'])->getJson("/api/v1/stock/purchase-orders/{$order}")
            ->assertOk()
            ->assertJsonPath('data.fulfillment.status', 'replacement_required')
            ->assertJsonPath('data.fulfillment.gross_received_quantity', 3)
            ->assertJsonPath('data.fulfillment.returned_quantity', 2)
            ->assertJsonPath('data.fulfillment.retained_quantity', 1)
            ->assertJsonPath('data.fulfillment.outstanding_quantity', 2)
            ->assertJsonPath('data.fulfillment.replacement_needed_quantity', 2)
            ->assertJsonPath('data.items.0.received_quantity', 3)
            ->assertJsonPath('data.items.0.pending_return_quantity', 0);

        $movementCount = (int) DB::table('stock_movements')->count();
        $this->assertSame(3, $movementCount);
        $this->as($fixture['creatorToken'])->postJson($uri, $attach)
            ->assertCreated()
            ->assertJsonPath('data.fulfillment.status', 'net_received')
            ->assertJsonPath('data.fulfillment.gross_received_quantity', 5)
            ->assertJsonPath('data.fulfillment.returned_quantity', 2)
            ->assertJsonPath('data.fulfillment.retained_quantity', 3)
            ->assertJsonPath('data.fulfillment.outstanding_quantity', 0)
            ->assertJsonPath('data.fulfillment.replacement_needed_quantity', 0)
            ->assertJsonPath('data.items.0.received_quantity', 5)
            ->assertJsonPath('data.items.0.outstanding_quantity', 0);
        $this->as($fixture['creatorToken'])->postJson($uri, $attach)
            ->assertOk()->assertJsonPath('data.items.0.received_quantity', 5);
        $this->assertDatabaseCount('stock_purchase_receipt_matches', 2);
        $this->assertDatabaseCount('stock_movements', $movementCount);
        $this->assertSame(2, (int) DB::table('audit_entries')
            ->where('action', 'stock.purchase_order.receipt_matched')->count());
    }

    public function test_cancelled_return_does_not_release_receipt_quota(): void
    {
        $fixture = $this->fixture();
        [$order, $line, , $match] =
            $this->reconciledForReturn($fixture, 1, 'NF-REPLACEMENT-CANCEL');
        $request = $this->as($fixture['creatorToken'])
            ->postJson('/api/v1/stock/supplier-returns', [
                'request_id' => (string) Str::uuid(),
                'purchase_receipt_match_id' => $match,
                'quantity' => 1, 'reason' => 'Solicitação que será cancelada',
            ])->assertCreated()->json('data.id');
        $receipt = $this->documentedPurchaseReceipt(
            $fixture, 1, 6875, 'NF-REPLACEMENT-BLOCKED'
        );
        $uri = "/api/v1/stock/purchase-orders/{$order}/reconcile-receipt";
        $attach = ['purchase_order_line_id' => $line, 'receipt_detail_id' => $receipt];

        $this->as($fixture['creatorToken'])->postJson($uri, $attach)->assertUnprocessable();
        $this->as($fixture['creatorToken'])
            ->postJson("/api/v1/stock/supplier-returns/{$request}/cancel", [
                'expected_version' => 1,
                'reason' => 'Revisão interna cancelou a devolução',
            ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->as($fixture['creatorToken'])->postJson($uri, $attach)->assertUnprocessable();
        $this->as($fixture['creatorToken'])->getJson("/api/v1/stock/purchase-orders/{$order}")
            ->assertOk()
            ->assertJsonPath('data.fulfillment.status', 'net_received')
            ->assertJsonPath('data.fulfillment.returned_quantity', 0)
            ->assertJsonPath('data.fulfillment.pending_return_quantity', 0)
            ->assertJsonPath('data.fulfillment.outstanding_quantity', 0);
        $this->assertDatabaseCount('stock_purchase_receipt_matches', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

}
