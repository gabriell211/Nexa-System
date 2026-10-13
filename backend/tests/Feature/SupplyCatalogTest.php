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

final class SupplyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant,string $role='owner'): string
    {
        $user=User::query()->create([
            'name'=>'Stock Tester','email'=>Str::uuid().'@example.test','password'=>'secret-test-password',
        ]);
        $tenant->users()->attach($user->id,['role'=>$role]);
        return $user->createToken('test',['api','tenant:'.$tenant->id])->plainTextToken;
    }

    private function fixture(): array
    {
        $tenant=Tenant::query()->create(['name'=>'Supply Provider','slug'=>'supplies']);
        $token=$this->token($tenant);
        $item=$this->withToken($token)->postJson('/api/v1/stock/items',[
            'sku'=>'TK-001','name'=>'Toner compatível preto','category'=>'toner',
            'brand'=>'Seven Supplies','color'=>'black','price_cents'=>15990,
            'cost_cents'=>6850,'yield_pages'=>7200,
        ])->assertCreated()->assertJsonPath('data.cost_cents',6850)->json('data.id');
        $location=$this->withToken($token)->postJson('/api/v1/stock/locations',[
            'name'=>'Depósito A','kind'=>'provider',
        ])->assertCreated()->json('data.id');
        return compact('tenant','token','item','location');
    }

    private function supplier(string $token): int
    {
        return $this->withToken($token)->postJson('/api/v1/stock/suppliers',[
            'code'=>'DISTR-01','name'=>'Distribuidora XPTO',
            'email'=>'purchases@example.test','document'=>'12345678000199',
        ])->assertCreated()->assertJsonPath('data.code','DISTR-01')->json('data.id');
    }

    private function receipt(
        string $token,int $item,int $location,int $supplier,?string $id=null,
    ): array {
        return [
            'request_id'=>$id??(string)Str::uuid(),
            'supplier_id'=>$supplier,'invoice_reference'=>'NF-2026-010',
            'lot_number'=>'LOT-10','expires_on'=>now('UTC')->addMonths(3)->toDateString(),
            'unit_cost_cents'=>6875,'item_id'=>$item,'destination_location_id'=>$location,
            'quantity'=>3,'reason'=>'Entrada fiscal confirmada no depósito',
        ];
    }

    public function test_supplier_catalog_and_sku_use_integer_cents_and_reject_duplicates(): void
    {
        extract($this->fixture());
        $supplier=$this->supplier($token);
        $this->withToken($token)->getJson('/api/v1/stock/suppliers')
            ->assertOk()->assertJsonPath('data.0.id',$supplier)
            ->assertJsonPath('data.0.name','Distribuidora XPTO');

        $this->withToken($token)->postJson('/api/v1/stock/suppliers',[
            'code'=>'DISTR-01','name'=>'Mesmo código',
        ])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/stock/items')
            ->assertOk()->assertJsonPath('data.0.price_cents',15990)
            ->assertJsonPath('data.0.yield_pages',7200);
        $this->withToken($token)->postJson('/api/v1/stock/items',[
            'sku'=>'BAD-CURRENCY','name'=>'Toner inválido','category'=>'toner',
            'price_cents'=>'10.99',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_items',1);
    }

    public function test_printer_compatibility_is_normalized_and_missing_is_unknown(): void
    {
        extract($this->fixture());
        $client=$tenant->customers()->create(['name'=>'Cliente']);
        $printer=$tenant->printers()->create([
            'customer_id'=>$client->id,'manufacturer'=>'Kyocera','model'=>'MA5500 IFX',
        ]);
        $otherPrinter=$tenant->printers()->create([
            'customer_id'=>$client->id,'manufacturer'=>'Ricoh','model'=>'MP 4055',
        ]);
        $uri="/api/v1/stock/items/{$item}/compatibilities";
        $compat=$this->withToken($token)->postJson($uri,[
            'printer_manufacturer'=>'  KYOCERA   ',
            'printer_model'=>'  MA5500   ifx  ',
        ])->assertCreated()->assertJsonPath('data.printer_manufacturer','kyocera')
            ->assertJsonPath('data.printer_model','ma5500 ifx')->json('data.id');
        $this->withToken($token)->postJson($uri,[
            'printer_manufacturer'=>'kyocera','printer_model'=>'MA5500 IFX',
        ])->assertUnprocessable();

        $this->withToken($token)->getJson($uri)
            ->assertOk()->assertJsonCount(1,'data');
        $this->withToken($token)->getJson("/api/v1/stock/items/{$item}/compatibility-check?printer_id={$printer->id}")
            ->assertOk()->assertJsonPath('status','confirmed')->assertJsonPath('confirmed',true);
        $this->withToken($token)->getJson("/api/v1/stock/items/{$item}/compatibility-check?printer_id={$otherPrinter->id}")
            ->assertOk()->assertJsonPath('status','unknown')->assertJsonPath('confirmed',false);
        $this->withToken($token)->deleteJson($uri.'/'.$compat)->assertNoContent();
        $this->withToken($token)->getJson($uri)->assertOk()->assertJsonCount(0,'data');
        $this->assertDatabaseHas('audit_entries',['action'=>'stock.compatibility.removed']);
    }

    public function test_documented_receipt_is_atomic_and_retry_preserves_original_cost_and_lot(): void
    {
        extract($this->fixture());
        $supplier=$this->supplier($token);
        $payload=$this->receipt($token,$item,$location,$supplier);
        $first=$this->withToken($token)->postJson('/api/v1/stock/receipts',$payload)
            ->assertCreated()->assertJsonPath('data.invoice_reference','NF-2026-010')
            ->assertJsonPath('data.lot_number','LOT-10')
            ->assertJsonPath('data.unit_cost_cents',6875)
            ->assertJsonPath('data.movement.quantity',3)->json('data.id');
        $this->withToken($token)->postJson('/api/v1/stock/receipts',$payload)
            ->assertCreated()->assertJsonPath('data.id',$first);
        $this->withToken($token)->getJson('/api/v1/stock/receipts')
            ->assertOk()->assertJsonCount(1,'data');
        $this->withToken($token)->postJson('/api/v1/stock/receipts',[
            ...$payload,'unit_cost_cents'=>9999,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_receipt_details',1);
        $this->assertDatabaseCount('stock_movements',1);
        $this->assertDatabaseHas('stock_balances',[
            'tenant_id'=>$tenant->id,'item_id'=>$item,'location_id'=>$location,'quantity'=>3,
        ]);
        $this->assertDatabaseHas('audit_entries',['action'=>'stock.receipt.documented']);
    }

    public function test_invalid_supplier_and_expired_lot_do_not_create_movement(): void
    {
        extract($this->fixture());
        $supplier=$this->supplier($token);
        $this->withToken($token)->postJson('/api/v1/stock/receipts',[
            ...$this->receipt($token,$item,$location,$supplier),
            'expires_on'=>now('UTC')->subDay()->toDateString(),
        ])->assertUnprocessable();

        $foreign=Tenant::query()->create(['name'=>'Foreign','slug'=>'foreign-supplies']);
        $foreignToken=$this->token($foreign);
        Auth::forgetGuards();
        $foreignSupplier=$this->withToken($foreignToken)->postJson('/api/v1/stock/suppliers',[
            'code'=>'FOREIGN','name'=>'Supplier another tenant',
        ])->assertCreated()->json('data.id');

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/stock/receipts',[
            ...$this->receipt($token,$item,$location,$supplier),
            'supplier_id'=>$foreignSupplier,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements',0);
        $this->assertDatabaseCount('stock_receipt_details',0);
    }

    public function test_cross_tenant_compatibility_and_receipt_lookups_cannot_leak(): void
    {
        extract($this->fixture());
        $supplier=$this->supplier($token);
        $this->withToken($token)->postJson('/api/v1/stock/receipts',
            $this->receipt($token,$item,$location,$supplier))->assertCreated();
        $foreign=Tenant::query()->create(['name'=>'Other','slug'=>'other-supply']);
        $foreignToken=$this->token($foreign);
        Auth::forgetGuards();
        $this->withToken($foreignToken)->getJson('/api/v1/stock/receipts')->assertOk()->assertJsonCount(0,'data');
        $this->withToken($foreignToken)->getJson("/api/v1/stock/items/{$item}/compatibilities")->assertNotFound();
        $this->withToken($foreignToken)->postJson("/api/v1/stock/items/{$item}/compatibilities",[
            'printer_manufacturer'=>'Kyocera','printer_model'=>'MA5500 IFX',
        ])->assertNotFound();
    }

    public function test_warehouse_can_register_receipts_but_restricted_portfolio_cannot(): void
    {
        extract($this->fixture());
        $supplier=$this->supplier($token);
        $warehouseToken=$this->token($tenant,'warehouse');
        Auth::forgetGuards();
        $this->withToken($warehouseToken)->postJson('/api/v1/stock/receipts',
            $this->receipt($warehouseToken,$item,$location,$supplier))->assertCreated();

        $employee=User::query()->create([
            'name'=>'Restricted Warehouse','email'=>Str::uuid().'@example.test',
            'password'=>'secret-test-password',
        ]);
        $tenant->users()->attach($employee->id,['role'=>'warehouse','customer_scope'=>'assigned']);
        $restricted=$employee->createToken('test',['api','tenant:'.$tenant->id])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($restricted)->postJson('/api/v1/stock/receipts',
            $this->receipt($restricted,$item,$location,$supplier))->assertForbidden();
        $this->withToken($restricted)->getJson('/api/v1/stock/suppliers')->assertForbidden();
        $this->assertDatabaseCount('stock_receipt_details',1);
    }
}
