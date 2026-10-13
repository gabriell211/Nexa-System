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

final class StockShipmentTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): string
    {
        $user = User::query()->create([
            'name'=>'Warehouse User','email'=>Str::uuid().'@example.test','password'=>'test-secret-value',
        ]);
        $tenant->users()->attach($user->id, ['role'=>$role]);
        return $user->createToken('test', ['api','tenant:'.$tenant->id])->plainTextToken;
    }

    private function fixture(): array
    {
        $tenant = Tenant::query()->create(['name'=>'Provider','slug'=>'shipper']);
        $token = $this->token($tenant);
        $item = $this->withToken($token)->postJson('/api/v1/stock/items', [
            'sku'=>'TONER-01','name'=>'Toner preto','category'=>'toner',
        ])->assertCreated()->json('data.id');
        $from = $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name'=>'Main warehouse','kind'=>'provider',
        ])->assertCreated()->json('data.id');
        $to = $this->withToken($token)->postJson('/api/v1/stock/locations', [
            'name'=>'Technical warehouse','kind'=>'technician',
            'technician_user_id'=>DB::table('tenant_user')->where('tenant_id', $tenant->id)->value('user_id'),
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/stock/movements', [
            'request_id'=>(string) Str::uuid(),'kind'=>'receipt','item_id'=>$item,
            'source_location_id'=>null,'destination_location_id'=>$from,
            'quantity'=>10,'reason'=>'Initial delivery into warehouse',
        ])->assertCreated();
        return compact('tenant','token','item','from','to');
    }

    private function reserve(string $token, int $item, int $from, int $to, int $quantity, ?string $uuid = null): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->withToken($token)->postJson('/api/v1/stock/shipments', [
            'request_id'=>$uuid ?? (string) Str::uuid(),'item_id'=>$item,
            'source_location_id'=>$from,'destination_location_id'=>$to,
            'quantity'=>$quantity,'reason'=>'Stock replenishment requested',
        ]);
    }

    private function action(string $token, int $id, string $kind, int $quantity, ?string $uuid = null): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        $data=['request_id'=>$uuid ?? (string) Str::uuid(),'reason'=>'Confirmed by stock operator'];
        if ($kind !== 'close') $data['quantity']=$quantity;
        return $this->withToken($token)->postJson("/api/v1/stock/shipments/{$id}/{$kind}", $data);
    }

    public function test_reservation_is_not_a_physical_transfer_and_blocks_free_stock(): void
    {
        extract($this->fixture());
        $id = $this->reserve($token,$item,$from,$to,7)->assertCreated()
            ->assertJsonPath('data.reserved_quantity',7)->json('data.id');

        $this->withToken($token)->getJson('/api/v1/stock/balances')
            ->assertOk()->assertJsonPath('data.0.quantity',10)
            ->assertJsonPath('data.0.reserved_quantity',7)
            ->assertJsonPath('data.0.available_quantity',3);
        $this->withToken($token)->postJson('/api/v1/stock/movements', [
            'request_id'=>(string) Str::uuid(),'kind'=>'issue','item_id'=>$item,
            'source_location_id'=>$from,'destination_location_id'=>null,
            'quantity'=>4,'reason'=>'Blocked because stock is reserved',
        ])->assertUnprocessable();

        $this->reserve($token,$item,$from,$to,4)->assertUnprocessable();
        $this->reserve($token,$item,$from,$to,3)->assertCreated();
        $this->withToken($token)->getJson("/api/v1/stock/shipments/{$id}")
            ->assertOk()->assertJsonPath('data.status','reserved');
        $this->assertDatabaseHas('stock_balances', [
            'item_id'=>$item,'location_id'=>$from,'quantity'=>10,
        ]);
    }

    public function test_partial_dispatch_receipt_and_close_preserve_balances_and_accounting(): void
    {
        extract($this->fixture());
        $id = $this->reserve($token,$item,$from,$to,6)->assertCreated()->json('data.id');

        $this->action($token,$id,'dispatch',2)->assertOk()
            ->assertJsonPath('data.shipped_quantity',2)
            ->assertJsonPath('data.reserved_quantity',4)
            ->assertJsonPath('data.in_transit_quantity',2);
        $this->action($token,$id,'dispatch',1)->assertOk()
            ->assertJsonPath('data.shipped_quantity',3)
            ->assertJsonPath('data.reserved_quantity',3);
        $this->action($token,$id,'receive',2)->assertOk()
            ->assertJsonPath('data.received_quantity',2)
            ->assertJsonPath('data.in_transit_quantity',1);
        $this->action($token,$id,'receive',1)->assertOk()
            ->assertJsonPath('data.received_quantity',3)
            ->assertJsonPath('data.in_transit_quantity',0);

        $this->action($token,$id,'close',0)->assertOk()
            ->assertJsonPath('data.status','closed_partial')
            ->assertJsonPath('data.cancelled_quantity',3)
            ->assertJsonPath('data.lost_quantity',0);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id'=>$tenant->id,'item_id'=>$item,'location_id'=>$from,'quantity'=>7,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id'=>$tenant->id,'item_id'=>$item,'location_id'=>$to,'quantity'=>3,
        ]);
        $this->assertDatabaseCount('stock_shipment_events',5);
        $this->withToken($token)->getJson('/api/v1/stock/balances')
            ->assertOk()->assertJsonPath('data.0.available_quantity',7);
        $this->withToken($token)->getJson("/api/v1/stock/shipments/{$id}")
            ->assertOk()->assertJsonCount(5,'data.events');
        $this->action($token,$id,'dispatch',1)->assertUnprocessable();
    }

    public function test_fully_received_shipment_completes_and_receipts_cannot_exceed_transit(): void
    {
        extract($this->fixture());
        $id=$this->reserve($token,$item,$from,$to,2)->assertCreated()->json('data.id');
        $this->action($token,$id,'receive',1)->assertUnprocessable();
        $this->action($token,$id,'dispatch',2)->assertOk()->assertJsonPath('data.status','in_transit');
        $this->action($token,$id,'receive',3)->assertUnprocessable();
        $this->action($token,$id,'receive',2)->assertOk()
            ->assertJsonPath('data.status','received')
            ->assertJsonPath('data.in_transit_quantity',0);
        $this->action($token,$id,'receive',1)->assertUnprocessable();
        $this->assertDatabaseHas('stock_balances', ['item_id'=>$item,'location_id'=>$from,'quantity'=>8]);
        $this->assertDatabaseHas('stock_balances', ['item_id'=>$item,'location_id'=>$to,'quantity'=>2]);
    }

    public function test_closing_with_shortage_records_loss_without_crediting_destination(): void
    {
        extract($this->fixture());
        $id=$this->reserve($token,$item,$from,$to,5)->assertCreated()->json('data.id');
        $this->action($token,$id,'dispatch',5)->assertOk();
        $this->action($token,$id,'receive',2)->assertOk();
        $this->action($token,$id,'close',0)->assertOk()
            ->assertJsonPath('data.status','closed_with_loss')
            ->assertJsonPath('data.lost_quantity',3);
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$from,'quantity'=>5]);
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$to,'quantity'=>2]);
        $this->assertDatabaseCount('stock_movements',3);
    }

    public function test_idempotency_cannot_duplicate_reserved_or_dispatched_units(): void
    {
        extract($this->fixture());
        $uuid=(string) Str::uuid();
        $id=$this->reserve($token,$item,$from,$to,5,$uuid)->assertCreated()->json('data.id');
        $this->reserve($token,$item,$from,$to,5,$uuid)->assertCreated()->assertJsonPath('data.id',$id);
        $this->reserve($token,$item,$from,$to,6,$uuid)->assertUnprocessable();

        $dispatch=(string) Str::uuid();
        $this->action($token,$id,'dispatch',3,$dispatch)->assertOk()
            ->assertJsonPath('data.shipped_quantity',3);
        $this->action($token,$id,'dispatch',3,$dispatch)->assertOk()
            ->assertJsonPath('data.shipped_quantity',3);
        $this->action($token,$id,'dispatch',2,$dispatch)->assertUnprocessable();
        $this->assertDatabaseCount('stock_shipments',1);
        $this->assertDatabaseCount('stock_shipment_events',1);
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$from,'quantity'=>7]);
    }

    public function test_cancel_reserved_shipment_restores_available_without_creating_stock(): void
    {
        extract($this->fixture());
        $id=$this->reserve($token,$item,$from,$to,10)->assertCreated()->json('data.id');
        $this->action($token,$id,'close',0)->assertOk()
            ->assertJsonPath('data.status','cancelled')
            ->assertJsonPath('data.cancelled_quantity',10);
        $this->reserve($token,$item,$from,$to,10)->assertCreated();
        $this->assertDatabaseCount('stock_movements',1);
    }

    public function test_foreign_tenant_and_restricted_roles_cannot_read_or_move_shipments(): void
    {
        extract($this->fixture());
        $id=$this->reserve($token,$item,$from,$to,1)->assertCreated()->json('data.id');
        $foreign=Tenant::query()->create(['name'=>'Other','slug'=>'other-shipment']);
        $outsider=$this->token($foreign);
        Auth::forgetGuards();
        $this->withToken($outsider)->getJson("/api/v1/stock/shipments/{$id}")->assertNotFound();
        $this->action($outsider,$id,'dispatch',1)->assertNotFound();
        $technician=$this->token($tenant,'technician');
        Auth::forgetGuards();
        $this->withToken($technician)->getJson('/api/v1/stock/shipments')->assertForbidden();
        $this->action($technician,$id,'dispatch',1)->assertForbidden();
        $this->assertDatabaseCount('stock_shipment_events',0);
    }

    public function test_only_supervisory_roles_can_write_off_in_transit_loss(): void
    {
        extract($this->fixture());
        $id = $this->reserve($token,$item,$from,$to,2)->assertCreated()->json('data.id');
        $warehouse = $this->token($tenant, 'warehouse');
        $this->action($warehouse,$id,'dispatch',2)->assertOk();
        $this->action($warehouse,$id,'close',0)->assertForbidden();
        $this->action($token,$id,'close',0)->assertOk()
            ->assertJsonPath('data.status','closed_with_loss')
            ->assertJsonPath('data.lost_quantity',2);
    }
}
