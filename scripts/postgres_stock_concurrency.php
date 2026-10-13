<?php
declare(strict_types=1);

/**
 * Standalone PostgreSQL concurrency smoke, intentionally not wrapped in
 * PHPUnit RefreshDatabase transactions. Each child has a fresh DB connection.
 */
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryLedgerService;
use App\Services\StockShipmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDefaultConnection() !== 'pgsql') {
    fwrite(STDERR, "Concurrency smoke only runs against PostgreSQL.\n");
    exit(2);
}
if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl_fork is required for a real process concurrency test.\n");
    exit(2);
}

$tenant = Tenant::query()->create([
    'name'=>'Concurrency smoke', 'slug'=>'concurrency-'.Str::lower(Str::random(12)),
]);
$user = User::query()->create([
    'name'=>'Concurrency owner','email'=>Str::uuid().'@example.test',
    'password'=>'not-used-by-this-test',
]);
$tenant->users()->attach($user->id,['role'=>'owner']);
$item = StockItem::query()->create([
    'tenant_id'=>$tenant->id,'sku'=>'CONCURRENT-'.Str::upper(Str::random(8)),
    'name'=>'One unit for concurrency','category'=>'toner','unit'=>'un','active'=>true,
]);
$source=StockLocation::query()->create([
    'tenant_id'=>$tenant->id,'name'=>'Source','kind'=>'provider','active'=>true,
]);
$destination=StockLocation::query()->create([
    'tenant_id'=>$tenant->id,'name'=>'Destination','kind'=>'provider','active'=>true,
]);
DB::table('stock_balances')->insert([
    'tenant_id'=>$tenant->id,'item_id'=>$item->id,'location_id'=>$source->id,
    'quantity'=>1,'created_at'=>now(),'updated_at'=>now(),
]);

/** @param Closure(int):void $operation */
function race(string $label, Closure $operation): void
{
    $files=[];$pids=[];
    foreach ([0,1] as $i) {
        $path=tempnam(sys_get_temp_dir(),'nexa-race-');
        if (!$path) throw new RuntimeException('Cannot allocate temporary result file.');
        $pid=pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Could not fork concurrency child.');
        if ($pid === 0) {
            DB::purge('pgsql');
            DB::setDefaultConnection('pgsql');
            try {
                // Synchronize both workers while allowing PostgreSQL to
                // determine lock acquisition order naturally.
                usleep(100_000);
                $operation($i);
                file_put_contents($path,'success');
            } catch (ValidationException $e) {
                file_put_contents($path,'rejected');
            } catch (Throwable $e) {
                file_put_contents($path,'error: '.get_class($e).' '. $e->getMessage());
            }
            exit(0);
        }
        $files[]=$path;$pids[]=$pid;
    }
    foreach ($pids as $pid) {
        $wait=pcntl_waitpid($pid,$status);
        if ($wait !== $pid || !pcntl_wifexited($status) || pcntl_wexitstatus($status)!==0) {
            throw new RuntimeException($label.': abnormal child exit');
        }
    }
    $results=array_map(static fn ($path): string => trim((string)file_get_contents($path)), $files);
    foreach ($files as $path) @unlink($path);
    sort($results);
    if ($results!==['rejected','success']) {
        throw new RuntimeException($label.' expected one success and one validation refusal, got '.json_encode($results));
    }
    echo "[OK] {$label}: one success, one rejected\n";
}

race('Concurrent last-unit reservations', static function (int $i) use ($tenant,$user,$item,$source,$destination): void {
    app(StockShipmentService::class)->reserve($tenant,$user,[
        'request_id'=>(string)Str::uuid(),
        'item_id'=>$item->id,'source_location_id'=>$source->id,
        'destination_location_id'=>$destination->id,
        'quantity'=>1,'reason'=>'Parallel warehouse replenishment',
    ]);
});
$shipments=DB::table('stock_shipments')->where('tenant_id',$tenant->id)->get();
if ($shipments->count()!==1) throw new RuntimeException('Reservation race created incorrect shipment count.');

try {
    app(InventoryLedgerService::class)->move($tenant,$user,[
        'request_id'=>(string)Str::uuid(),
        'kind'=>'issue','item_id'=>$item->id,'source_location_id'=>$source->id,
        'destination_location_id'=>null,'quantity'=>1,
        'reason'=>'Should not spend a reserved unit',
    ]);
    throw new RuntimeException('Physical stock issued while fully reserved!');
} catch (ValidationException) {
    echo "[OK] Direct issue respects the reservation\n";
}

app(StockShipmentService::class)->close($tenant,$user,(int)$shipments[0]->id,[
    'request_id'=>(string)Str::uuid(),'reason'=>'Cancel reservation for second race',
]);

race('Concurrent last-unit issues', static function (int $i) use ($tenant,$user,$item,$source): void {
    app(InventoryLedgerService::class)->move($tenant,$user,[
        'request_id'=>(string)Str::uuid(),
        'kind'=>'issue','item_id'=>$item->id,'source_location_id'=>$source->id,
        'destination_location_id'=>null,'quantity'=>1,
        'reason'=>'Parallel last-unit consumption',
    ]);
});

$balance=(int)DB::table('stock_balances')->where('tenant_id',$tenant->id)
    ->where('item_id',$item->id)->where('location_id',$source->id)->value('quantity');
$issues=DB::table('stock_movements')->where('tenant_id',$tenant->id)->where('kind','issue')->count();
if ($balance!==0 || $issues!==1) {
    throw new RuntimeException("Inconsistent result: physical={$balance}, issue_movements={$issues}");
}
echo "[OK] PostgreSQL row-lock serialization: quantity=0, issues=1\n";
