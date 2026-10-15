<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StockCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_options_are_filtered_within_tenant_before_pagination(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $foreign = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $user = User::query()->create([
            'name' => 'Owner', 'email' => Str::uuid().'@example.test',
            'password' => 'search-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken;

        for ($index = 0; $index < 30; $index++) {
            StockItem::query()->create([
                'tenant_id' => $tenant->id, 'sku' => sprintf('A%02d', $index),
                'name' => sprintf('Alpha %02d', $index), 'category' => 'toner', 'unit' => 'un',
            ]);
            StockLocation::query()->create([
                'tenant_id' => $tenant->id, 'name' => sprintf('Alpha %02d', $index),
                'kind' => 'provider',
            ]);
        }

        $item = StockItem::query()->create([
            'tenant_id' => $tenant->id, 'sku' => 'Z-NEEDLE',
            'name' => 'Zulu item', 'category' => 'toner', 'unit' => 'un',
        ]);
        $location = StockLocation::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Zulu needle warehouse', 'kind' => 'provider',
        ]);
        StockItem::query()->create([
            'tenant_id' => $tenant->id, 'sku' => 'ZZZ-NEEDLE',
            'name' => 'Inactive', 'category' => 'toner', 'unit' => 'un', 'active' => false,
        ]);
        StockLocation::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Zzz needle inactive',
            'kind' => 'provider', 'active' => false,
        ]);
        StockItem::query()->create([
            'tenant_id' => $foreign->id, 'sku' => 'FOREIGN-NEEDLE',
            'name' => 'Foreign', 'category' => 'toner', 'unit' => 'un',
        ]);
        StockLocation::query()->create([
            'tenant_id' => $foreign->id, 'name' => 'Foreign needle', 'kind' => 'provider',
        ]);

        $this->withToken($token)->getJson('/api/v1/stock/items?page=2&per_page=25')
            ->assertOk()->assertJsonPath('data.5.id', $item->id);
        $this->withToken($token)->getJson('/api/v1/stock/locations?page=2&per_page=25')
            ->assertOk()->assertJsonPath('data.5.id', $location->id);
        $this->withToken($token)->getJson('/api/v1/stock/items?q=needle&active_only=true')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $item->id);
        $this->withToken($token)->getJson('/api/v1/stock/locations?q=NEEDLE&active_only=true')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $location->id);
        $this->withToken($token)->getJson('/api/v1/stock/items?q[]=needle')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/stock/locations?q[]=needle')->assertUnprocessable();
    }
}
