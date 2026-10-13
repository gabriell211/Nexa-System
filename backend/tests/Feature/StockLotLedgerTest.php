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

final class StockLotLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): string
    {
        $user = User::query()->create([
            'name'=>'Lot Operator','email'=>Str::uuid().'@example.test',
            'password'=>'secure-testing-password',
        ]);
        $tenant->users()->attach($user->id, ['role'=>$role]);
        return $user->createToken('test',['api','tenant:'.$tenant->id])->plainTextToken;
    }

    private function fixture(): array
    {
        $tenant = Tenant::query()->create(['name'=>'Provider', 'slug'=>'lot-provider']);
        $token = $this->token($tenant);
        $item = $this->withToken($token)->postJson('/api/v1/stock/items',[
            'sku'=>'LOT-TEST-01','name'=>'Toner premium','category'=>'toner',
        ])->assertCreated()->json('data.id');
        $supplier = $this->withToken($token)->postJson('/api/v1/stock/suppliers',[
            'code'=>'SUP-A','name'=>'Fornecedor A',
        ])->assertCreated()->json('data.id');
        $from = $this->withToken($token)->postJson('/api/v1/stock/locations',[
            'name'=>'Almoxarifado A','kind'=>'provider',
        ])->assertCreated()->json('data.id');
        $to = $this->withToken($token)->postJson('/api/v1/stock/locations',[
            'name'=>'Almoxarifado B','kind'=>'provider',
        ])->assertCreated()->json('data.id');
        return compact('tenant','token','item','supplier','from','to');
    }

    private function receiveLot(
        string $token,int $item,int $supplier,int $destination,
        string $lot,int $days,int $quantity,
        ?string $uuid=null,
    ): array {
        Auth::forgetGuards();
        $payload=[
            'request_id'=>$uuid??(string)Str::uuid(),
            'supplier_id'=>$supplier,'item_id'=>$item,
            'destination_location_id'=>$destination,'quantity'=>$quantity,
            'invoice_reference'=>'NF-'.$lot,'lot_number'=>$lot,
            'unit_cost_cents'=>12000,'expires_on'=>now('UTC')->addDays($days)->toDateString(),
            'reason'=>'Recebimento documentado com lote',
        ];
        return [$this->withToken($token)->postJson('/api/v1/stock/receipts',$payload)
            ->assertCreated()->json('data'),$payload];
    }

    private function move(
        string $token,string $kind,int $item,?int $source,?int $destination,int $qty,
    ): \Illuminate\Testing\TestResponse {
        Auth::forgetGuards();
        return $this->withToken($token)->postJson('/api/v1/stock/movements',[
            'request_id'=>(string)Str::uuid(),'kind'=>$kind,'item_id'=>$item,
            'source_location_id'=>$source,'destination_location_id'=>$destination,
            'quantity'=>$qty,'reason'=>'Operação rastreada por lote FEFO',
        ]);
    }

    private function shipmentEvent(
        string $token,int $shipment,string $kind,int $quantity=0,
    ): \Illuminate\Testing\TestResponse {
        Auth::forgetGuards();
        $payload=['request_id'=>(string)Str::uuid(),'reason'=>'Operação logística auditada por lote'];
        if ($kind!=='close') $payload['quantity']=$quantity;
        return $this->withToken($token)->postJson(
            "/api/v1/stock/shipments/{$shipment}/{$kind}",$payload
        );
    }

    public function test_documented_receipt_creates_one_lot_without_changing_retry_balance(): void
    {
        extract($this->fixture());
        [$receipt,$payload]=$this->receiveLot($token,$item,$supplier,$from,'BATCH-A',30,5);
        $this->assertDatabaseHas('stock_lots',[
            'tenant_id'=>$tenant->id,'item_id'=>$item,
            'receipt_detail_id'=>$receipt['id'],'lot_number'=>'BATCH-A',
        ]);
        $lotId=(int)DB::table('stock_lots')->where('receipt_detail_id',$receipt['id'])->value('id');
        $this->assertDatabaseHas('stock_lot_balances',[
            'tenant_id'=>$tenant->id,'lot_id'=>$lotId,'location_id'=>$from,'quantity'=>5,
        ]);
        $this->assertDatabaseHas('stock_movement_lot_allocations',[
            'tenant_id'=>$tenant->id,'movement_id'=>$receipt['movement_id'],
            'lot_id'=>$lotId,'quantity'=>5,
        ]);
        $this->withToken($token)->postJson('/api/v1/stock/receipts',$payload)
            ->assertCreated()->assertJsonPath('data.id',$receipt['id']);
        $this->assertDatabaseCount('stock_lots',1);
        $this->assertDatabaseCount('stock_lot_balances',1);
        $this->withToken($token)->getJson('/api/v1/stock/lots')
            ->assertOk()->assertJsonPath('data.0.lot_number','BATCH-A')
            ->assertJsonPath('data.0.quantity',5)
            ->assertJsonPath('data.0.expired',false);
    }

    public function test_fefo_issue_then_transfer_preserves_per_lot_physical_positions(): void
    {
        extract($this->fixture());
        $this->receiveLot($token,$item,$supplier,$from,'LATE',90,4);
        $this->receiveLot($token,$item,$supplier,$from,'SOON',15,3);
        $ids=DB::table('stock_lots')->pluck('id','lot_number');
        $this->move($token,'issue',$item,$from,null,5)
            ->assertCreated()->assertJsonCount(2,'data.lot_allocations');
        $this->assertDatabaseHas('stock_lot_balances',[
            'lot_id'=>$ids['SOON'],'location_id'=>$from,'quantity'=>0,
        ]);
        $this->assertDatabaseHas('stock_lot_balances',[
            'lot_id'=>$ids['LATE'],'location_id'=>$from,'quantity'=>2,
        ]);
        $this->move($token,'transfer',$item,$from,$to,2)
            ->assertCreated()->assertJsonPath('data.lot_allocations.0.lot_number','LATE');
        $this->assertDatabaseHas('stock_lot_balances',[
            'lot_id'=>$ids['LATE'],'location_id'=>$from,'quantity'=>0,
        ]);
        $this->assertDatabaseHas('stock_lot_balances',[
            'lot_id'=>$ids['LATE'],'location_id'=>$to,'quantity'=>2,
        ]);
        $this->withToken($token)->getJson('/api/v1/stock/movements')
            ->assertOk()->assertJsonCount(4,'data');
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$from,'quantity'=>0]);
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$to,'quantity'=>2]);
    }

    public function test_expired_known_lot_cannot_be_consumed_and_does_not_change_physical_stock(): void
    {
        extract($this->fixture());
        [$receipt]=$this->receiveLot($token,$item,$supplier,$from,'EXPIRED-TEST',2,2);
        DB::table('stock_lots')->where('receipt_detail_id',$receipt['id'])
            ->update(['expires_on'=>now('UTC')->subDays(1)->toDateString()]);
        $this->move($token,'issue',$item,$from,null,1)->assertUnprocessable();
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$from,'quantity'=>2]);
        $this->assertDatabaseHas('stock_lot_balances',['location_id'=>$from,'quantity'=>2]);
        $this->assertDatabaseCount('stock_movements',1);
        $this->withToken($token)->getJson('/api/v1/stock/lots')
            ->assertOk()->assertJsonPath('data.0.expired',true);
    }

    public function test_mixed_unknown_legacy_stock_is_not_falsely_assigned_to_a_known_lot(): void
    {
        extract($this->fixture());
        $this->move($token,'receipt',$item,null,$from,3)->assertCreated()
            ->assertJsonCount(0,'data.lot_allocations');
        $this->receiveLot($token,$item,$supplier,$from,'TRACEABLE',50,2);
        $this->move($token,'issue',$item,$from,null,4)->assertCreated()
            ->assertJsonCount(1,'data.lot_allocations')
            ->assertJsonPath('data.lot_allocations.0.quantity',2);
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$from,'quantity'=>1]);
        $this->assertDatabaseHas('stock_lot_balances',['location_id'=>$from,'quantity'=>0]);
        $this->assertDatabaseCount('stock_movement_lot_allocations',2);
    }

    public function test_shipment_keeps_fefo_lots_in_transit_and_records_shortage_without_phantom_stock(): void
    {
        extract($this->fixture());
        $this->receiveLot($token,$item,$supplier,$from,'LATE',80,3);
        $this->receiveLot($token,$item,$supplier,$from,'SOON',10,2);
        $ids=DB::table('stock_lots')->pluck('id','lot_number');
        $shipment=$this->withToken($token)->postJson('/api/v1/stock/shipments',[
            'request_id'=>(string)Str::uuid(),'item_id'=>$item,
            'source_location_id'=>$from,'destination_location_id'=>$to,
            'quantity'=>4,'reason'=>'Reposição por lote auditada',
        ])->assertCreated()->json('data.id');
        $this->shipmentEvent($token,$shipment,'dispatch',4)
            ->assertOk()->assertJsonPath('data.in_transit_quantity',4);
        $this->assertDatabaseHas('stock_shipment_lot_transit',[
            'shipment_id'=>$shipment,'lot_id'=>$ids['SOON'],'shipped_quantity'=>2,
        ]);
        $this->assertDatabaseHas('stock_shipment_lot_transit',[
            'shipment_id'=>$shipment,'lot_id'=>$ids['LATE'],'shipped_quantity'=>2,
        ]);
        $this->shipmentEvent($token,$shipment,'receive',3)
            ->assertOk()->assertJsonPath('data.received_quantity',3);
        $this->assertDatabaseHas('stock_lot_balances',[
            'lot_id'=>$ids['SOON'],'location_id'=>$to,'quantity'=>2,
        ]);
        $this->assertDatabaseHas('stock_lot_balances',[
            'lot_id'=>$ids['LATE'],'location_id'=>$to,'quantity'=>1,
        ]);
        $this->shipmentEvent($token,$shipment,'close')->assertOk()
            ->assertJsonPath('data.lost_quantity',1)
            ->assertJsonPath('data.status','closed_with_loss');
        $this->assertDatabaseHas('stock_shipment_lot_transit',[
            'shipment_id'=>$shipment,'lot_id'=>$ids['LATE'],
            'shipped_quantity'=>2,'received_quantity'=>1,'lost_quantity'=>1,
        ]);
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$from,'quantity'=>1]);
        $this->assertDatabaseHas('stock_balances',['item_id'=>$item,'location_id'=>$to,'quantity'=>3]);
    }

    public function test_lot_positions_are_hidden_from_foreign_tenant_and_restricted_portfolio(): void
    {
        extract($this->fixture());
        $this->receiveLot($token,$item,$supplier,$from,'PRIVATE',30,2);
        $other=Tenant::query()->create(['name'=>'Different','slug'=>'different-lots']);
        $otherToken=$this->token($other);
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/stock/lots')
            ->assertOk()->assertJsonCount(0,'data');
        $limited=$this->token($tenant,'technician');
        Auth::forgetGuards();
        $this->withToken($limited)->getJson('/api/v1/stock/lots')->assertForbidden();
        $this->assertDatabaseCount('stock_lots',1);
    }
}
