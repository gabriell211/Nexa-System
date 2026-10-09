<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdministrativeSearchTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(Tenant $tenant): string
    {
        $user = User::create([
            'name' => 'Operator',
            'email' => 'operator+'.(string) $tenant->id.'@example.test',
            'password' => 'secure-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => 'admin']);

        return $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken;
    }

    public function test_search_filters_server_side_without_leaking_another_tenant(): void
    {
        $first = Tenant::create(['name' => 'First', 'slug' => 'first']);
        $second = Tenant::create(['name' => 'Second', 'slug' => 'second']);
        $first->customers()->create(['name' => 'Alpha Ltda']);
        $first->customers()->create(['name' => 'Beta Ltda']);
        $second->customers()->create(['name' => 'Alpha Secret']);

        $this->withToken($this->tokenFor($first))
            ->getJson('/api/v1/customers?q=ALPHA&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Alpha Ltda');
    }

    public function test_printer_filter_remains_tenant_scoped(): void
    {
        $first = Tenant::create(['name' => 'First', 'slug' => 'first']);
        $second = Tenant::create(['name' => 'Second', 'slug' => 'second']);
        $a = $first->customers()->create(['name' => 'Alpha']);
        $b = $second->customers()->create(['name' => 'Private']);
        $first->printers()->create(['customer_id' => $a->id, 'manufacturer' => 'Kyocera', 'model' => 'MA5500']);
        $second->printers()->create(['customer_id' => $b->id, 'manufacturer' => 'Kyocera', 'model' => 'Private']);

        $this->withToken($this->tokenFor($first))
            ->getJson('/api/v1/printers?q=kyocera')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_page_size_is_bounded(): void
    {
        $tenant = Tenant::create(['name' => 'First', 'slug' => 'first']);

        $this->withToken($this->tokenFor($tenant))
            ->getJson('/api/v1/customers?per_page=1000')
            ->assertUnprocessable();

        $this->withToken($this->tokenFor($tenant))
            ->getJson('/api/v1/printers?per_page=1000')
            ->assertUnprocessable();
    }
}
