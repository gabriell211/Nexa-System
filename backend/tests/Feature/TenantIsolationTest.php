<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_cannot_access_another_tenants_customer(): void
    {
        $first = Tenant::create(['name'=>'First','slug'=>'first']);
        $second = Tenant::create(['name'=>'Second','slug'=>'second']);
        $user = User::create(['name'=>'Admin','email'=>'admin@example.test','password'=>'example-strong-password']);
        $first->users()->attach($user->id, ['role'=>'owner']);
        $foreign = $second->customers()->create(['name'=>'Private']);
        $token = $user->createToken('test', ['api','tenant:'.$first->id]);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/customers/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_cross_tenant_printer_assignment_is_rejected(): void
    {
        $first = Tenant::create(['name'=>'First','slug'=>'first']);
        $second = Tenant::create(['name'=>'Second','slug'=>'second']);
        $user = User::create(['name'=>'Admin','email'=>'admin@example.test','password'=>'example-strong-password']);
        $first->users()->attach($user->id, ['role'=>'owner']);
        $foreign = $second->customers()->create(['name'=>'Private']);
        $token = $user->createToken('test', ['api','tenant:'.$first->id]);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/printers', ['customer_id'=>$foreign->id,'manufacturer'=>'Acme','model'=>'100'])
            ->assertUnprocessable();
    }
}
