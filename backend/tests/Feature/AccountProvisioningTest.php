<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ServiceTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $tenant = Tenant::query()->create(['name'=>'Provider','slug'=>'account-provider']);
        $alpha = $tenant->customers()->create(['name'=>'Alpha']);
        $beta = $tenant->customers()->create(['name'=>'Beta']);
        $owner = User::query()->create([
            'name'=>'Owner','email'=>'owner.'.Str::uuid().'@example.test',
            'password'=>'OwnerTestPassword123',
        ]);
        $tenant->users()->attach($owner->id,['role'=>'owner','customer_scope'=>'all']);
        $manager = User::query()->create([
            'name'=>'Manager','email'=>'manager.'.Str::uuid().'@example.test',
            'password'=>'ManagerTestPassword123',
        ]);
        $tenant->users()->attach($manager->id,['role'=>'manager','customer_scope'=>'all']);
        return compact('tenant','alpha','beta','owner','manager');
    }

    private function login(User $user, Tenant $tenant, string $password): void
    {
        $this->postJson('/api/v1/browser/auth/login', [
            'email'=>$user->email,'password'=>$password,'tenant'=>$tenant->slug,
        ])->assertOk();
    }

    public function test_owner_invites_disabled_customer_and_token_is_single_use_without_plaintext_storage(): void
    {
        extract($this->fixture());
        $this->login($owner,$tenant,'OwnerTestPassword123');
        $answer=$this->postJson('/api/v1/browser/accounts', [
            'name'=>'Branch client','email'=>'invite.'.Str::uuid().'@example.test',
            'account_type'=>'customer','customer_ids'=>[$alpha->id],
            'view_reports'=>false,'view_other_tickets'=>false,
        ])->assertCreated()->assertJsonPath('role','customer')->assertJsonPath('scope','assigned')
            ->assertJsonPath('user.active',false);
        $userId=(int)$answer->json('user.id');
        $raw=$answer->json('invitation.token');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$raw);
        $this->assertDatabaseHas('customer_account_settings', [
            'tenant_id'=>$tenant->id,'user_id'=>$userId,
            'view_reports'=>false,'view_other_tickets'=>false,
        ]);
        $this->assertDatabaseHas('customer_user_access', [
            'tenant_id'=>$tenant->id,'user_id'=>$userId,'customer_id'=>$alpha->id,
        ]);
        $this->assertDatabaseHas('account_invitations', [
            'user_id'=>$userId,'token_sha256'=>hash('sha256',$raw),
        ]);
        $this->assertDatabaseMissing('account_invitations',['token_sha256'=>$raw]);
        $audit=DB::table('audit_entries')->where('action','account.invited')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($raw,(string)$audit->after_state);

        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->postJson('/api/v1/browser/auth/login', [
            'email'=>$answer->json('user.email'),'tenant'=>$tenant->slug,
            'password'=>'NotYetActivated123',
        ])->assertUnprocessable();

        $payload=['tenant'=>$tenant->slug,'token'=>$raw,
            'password'=>'NewSecretPass123','password_confirmation'=>'NewSecretPass123'];
        $this->postJson('/api/v1/browser/auth/activate', $payload)->assertOk();
        $this->postJson('/api/v1/browser/auth/activate', $payload)->assertUnprocessable();
        $this->login(User::findOrFail($userId), $tenant, 'NewSecretPass123');
        $this->getJson('/api/v1/browser/auth/me')
            ->assertOk()->assertJsonPath('role','customer')
            ->assertJsonPath('customer_permissions.view_reports',false)
            ->assertJsonPath('customer_permissions.view_other_tickets',false);
        $this->getJson('/api/v1/browser/client/analytics')->assertForbidden();
        $this->getJson('/api/v1/browser/client/printers')->assertOk();
        $this->getJson('/api/v1/browser/staff')->assertForbidden();
    }

    public function test_managers_foreign_clients_existing_emails_and_broad_customer_scope_are_rejected(): void
    {
        extract($this->fixture());
        $foreign=Tenant::query()->create(['name'=>'Foreign','slug'=>'foreign-account']);
        $other=$foreign->customers()->create(['name'=>'Foreign client']);
        $payload=['name'=>'Scoped user','email'=>'scoped.'.Str::uuid().'@example.test',
            'account_type'=>'customer','customer_ids'=>[$alpha->id]];
        $this->login($manager,$tenant,'ManagerTestPassword123');
        $this->postJson('/api/v1/browser/accounts', $payload)->assertForbidden();

        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->login($owner,$tenant,'OwnerTestPassword123');
        $this->postJson('/api/v1/browser/accounts', [
            ...$payload,'customer_ids'=>[$alpha->id,$other->id],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/browser/accounts', [
            ...$payload,'email'=>$owner->email,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/browser/accounts', [
            ...$payload,'account_type'=>'owner',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('account_invitations', 0);

        $created=$this->postJson('/api/v1/browser/accounts', $payload)->assertCreated();
        $user=(int)$created->json('user.id');
        $this->putJson('/api/v1/browser/staff/'.$user.'/portfolio', [
            'scope'=>'all','customer_ids'=>[],
        ])->assertUnprocessable();
    }

    public function test_collaborator_invite_is_never_administrator_and_expired_tokens_fail(): void
    {
        extract($this->fixture());
        $this->login($owner,$tenant,'OwnerTestPassword123');
        $answer=$this->postJson('/api/v1/browser/accounts', [
            'name'=>'Technician','email'=>'tech.'.Str::uuid().'@example.test',
            'account_type'=>'collaborator','customer_ids'=>[$beta->id],
        ])->assertCreated()->assertJsonPath('role','technician');
        $userId=(int)$answer->json('user.id');
        $this->assertDatabaseHas('tenant_user',[
            'tenant_id'=>$tenant->id,'user_id'=>$userId,
            'role'=>'technician','customer_scope'=>'assigned',
        ]);
        DB::table('account_invitations')->where('user_id',$userId)
            ->update(['expires_at'=>now()->subMinute()]);
        $this->postJson('/api/v1/browser/auth/activate', [
            'tenant'=>$tenant->slug,'token'=>$answer->json('invitation.token'),
            'password'=>'ValidSecret123','password_confirmation'=>'ValidSecret123',
        ])->assertUnprocessable();
        $this->assertFalse((bool)User::findOrFail($userId)->active);
    }

    public function test_other_client_tickets_are_not_visible_until_explicitly_permitted(): void
    {
        extract($this->fixture());
        $this->login($owner,$tenant,'OwnerTestPassword123');
        $answer=$this->postJson('/api/v1/browser/accounts', [
            'name'=>'Ticket user','email'=>'tickets.'.Str::uuid().'@example.test',
            'account_type'=>'customer','customer_ids'=>[$alpha->id],
            'view_other_tickets'=>false,
        ])->assertCreated();
        $id=(int)$answer->json('user.id');
        $user=User::findOrFail($id);
        // Activate through the same one-time public route.
        $this->postJson('/api/v1/browser/auth/activate', [
            'tenant'=>$tenant->slug,'token'=>$answer->json('invitation.token'),
            'password'=>'ClientPassword123','password_confirmation'=>'ClientPassword123',
        ])->assertOk();
        $mine=ServiceTicket::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$alpha->id,
            'opened_by_user_id'=>$id,'subject'=>'Meu chamado','description'=>'Test description',
            'category'=>'outro','priority'=>'normal','status'=>'triage','origin'=>'customer_portal',
        ]);
        $hidden=ServiceTicket::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$alpha->id,
            'opened_by_user_id'=>$owner->id,'subject'=>'Outro solicitante','description'=>'Test description',
            'category'=>'outro','priority'=>'normal','status'=>'triage','origin'=>'backoffice',
        ]);
        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->login($user,$tenant,'ClientPassword123');
        $this->getJson('/api/v1/browser/client/tickets')->assertOk()
            ->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$mine->id);
        $this->getJson('/api/v1/browser/client/tickets/'.$hidden->id)->assertNotFound();
        DB::table('customer_account_settings')->where('tenant_id',$tenant->id)
            ->where('user_id',$id)->update(['view_other_tickets'=>true]);
        $this->getJson('/api/v1/browser/client/tickets')->assertOk()->assertJsonCount(2,'data');
    }
}
