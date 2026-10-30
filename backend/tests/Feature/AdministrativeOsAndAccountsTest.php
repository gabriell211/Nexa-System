<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdministrativeOsAndAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Tenant $tenant): User
    {
        $owner = User::query()->create([
            'name' => 'Proprietário Nexa',
            'email' => 'owner.'.Str::uuid().'@example.test',
            'password' => 'SecureOwnerPassword123',
        ]);
        $tenant->users()->attach($owner->id, ['role' => 'owner', 'customer_scope' => 'all']);

        return $owner;
    }

    private function login(User $user, Tenant $tenant, string $password): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/browser/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'tenant' => $tenant->slug,
        ])->assertOk();
    }

    public function test_owner_invites_named_administrator_who_opens_os_with_attributed_identity(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Nexa', 'slug' => 'nexa-admin-os']);
        $owner = $this->owner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Cliente A']);
        $email = 'administrador.'.Str::uuid().'@example.test';

        $this->login($owner, $tenant, 'SecureOwnerPassword123');
        $invitation = $this->postJson('/api/v1/browser/accounts', [
            'name' => 'Maria Administração',
            'email' => $email,
            'account_type' => 'administrator',
        ])->assertCreated()
            ->assertJsonPath('user.name', 'Maria Administração')
            ->assertJsonPath('user.email', $email)
            ->assertJsonPath('role', 'admin')
            ->assertJsonPath('scope', 'all')
            ->assertJsonPath('customer_ids', [])
            ->assertJsonPath('user.active', false);

        $adminId = $invitation->json('user.id');
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $adminId,
            'role' => 'admin',
            'customer_scope' => 'all',
        ]);
        $this->getJson('/api/v1/browser/staff')->assertOk()
            ->assertJsonFragment(['name' => 'Maria Administração', 'email' => $email]);

        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->postJson('/api/v1/browser/auth/activate', [
            'tenant' => $tenant->slug,
            'token' => $invitation->json('invitation.token'),
            'password' => 'SecureAdminPassword123',
            'password_confirmation' => 'SecureAdminPassword123',
        ])->assertOk();

        $admin = User::query()->findOrFail($adminId);
        $this->login($admin, $tenant, 'SecureAdminPassword123');
        $this->getJson('/api/v1/browser/auth/me')->assertOk()
            ->assertJsonPath('role', 'admin')
            ->assertJsonPath('user.email', $email);
        $os = $this->postJson('/api/v1/browser/tickets', [
            'customer_id' => $customer->id,
            'printer_id' => null,
            'subject' => 'Falha de impressão',
            'description' => 'Equipamento do cliente não imprime.',
            'category' => 'impressao',
            'priority' => 'high',
        ])->assertCreated()
            ->assertJsonPath('data.origin', 'backoffice')
            ->assertJsonPath('data.opened_by_user_id', $adminId)
            ->assertJsonPath('data.opener.name', 'Maria Administração')
            ->assertJsonPath('data.opener.email', $email);

        $id = $os->json('data.id');
        $this->getJson('/api/v1/browser/tickets')->assertOk()
            ->assertJsonPath('data.0.opener.email', $email);
        $this->getJson('/api/v1/browser/tickets/'.$id)->assertOk()
            ->assertJsonPath('data.opener.id', $adminId);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ticket.created', 'actor_user_id' => $adminId]);
    }

    public function test_admin_cannot_invite_other_administrators_or_assign_a_customer_portfolio_to_admin(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Nexa', 'slug' => 'nexa-admin-security']);
        $owner = $this->owner($tenant);
        $admin = User::query()->create([
            'name' => 'Paulo Admin', 'email' => 'paulo.'.Str::uuid().'@example.test',
            'password' => 'SecureAdminPassword123',
        ]);
        $tenant->users()->attach($admin->id, ['role' => 'admin', 'customer_scope' => 'all']);
        $customer = $tenant->customers()->create(['name' => 'Cliente protegido']);

        $this->login($admin, $tenant, 'SecureAdminPassword123');
        $this->postJson('/api/v1/browser/accounts', [
            'name' => 'Admin sem autorização',
            'email' => 'blocked.'.Str::uuid().'@example.test',
            'account_type' => 'administrator',
        ])->assertForbidden();

        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        $this->login($owner, $tenant, 'SecureOwnerPassword123');
        $this->postJson('/api/v1/browser/accounts', [
            'name' => 'Admin com carteira',
            'email' => 'invalid.'.Str::uuid().'@example.test',
            'account_type' => 'administrator',
            'customer_ids' => [$customer->id],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/browser/accounts', [
            'name' => 'X', 'email' => 'invalid@example.test',
            'account_type' => 'administrator',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('account_invitations', 0);
    }
}
