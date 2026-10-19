<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClientTicketMediaTest extends TestCase
{
    use RefreshDatabase;

    private function newTenant(string $slug): Tenant
    {
        return Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
    }

    private function access(Tenant $tenant, int $customerId, string $role = 'customer', string $scope = 'assigned'): User
    {
        $user = User::query()->create([
            'name' => 'Helpdesk client',
            'email' => (string) Str::uuid().'@example.test',
            'password' => 'secure-portal-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'customer_scope' => $scope]);
        if ($scope === 'assigned') {
            DB::table('customer_user_access')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'customer_id' => $customerId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $user;
    }

    private function login(User $user, Tenant $tenant): void
    {
        $this->postJson('/api/v1/browser/auth/login', [
            'email' => $user->email,
            'tenant' => $tenant->slug,
            'password' => 'secure-portal-password',
        ])->assertOk();
    }

    private function printer(Tenant $tenant, int $customerId, string $model): int
    {
        return $tenant->printers()->create([
            'customer_id' => $customerId, 'manufacturer' => 'Brother',
            'model' => $model, 'active' => true,
        ])->id;
    }

    public function test_customer_can_open_ticket_with_multiple_owned_printers_and_private_photo_and_video(): void
    {
        Storage::fake('local');
        $tenant = $this->newTenant('tenant-ticket');
        $customer = $tenant->customers()->create(['name' => 'My branch']);
        $otherCustomer = $tenant->customers()->create(['name' => 'Private branch']);
        $p1 = $this->printer($tenant, $customer->id, 'MFC-L');
        $p2 = $this->printer($tenant, $customer->id, 'DCP-L');
        $secret = $this->printer($tenant, $otherCustomer->id, 'PRIVATE');
        $user = $this->access($tenant, $customer->id);
        $this->login($user, $tenant);
        $answer = $this->post('/api/v1/browser/client/tickets', [
            'category' => 'painel_atolado',
            'description' => 'A impressora está atolando papel repetidamente.',
            'printer_ids' => [$p1, $p2],
            'attachments' => [
                UploadedFile::fake()->image('evidencia.jpg'),
                UploadedFile::fake()->create('barulho.mp4', 64, 'video/mp4'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.subject', 'Impressora atolando papel')
            ->assertJsonPath('data.status', 'triage')
            ->assertJsonCount(2, 'data.printers')
            ->assertJsonCount(2, 'data.attachments')
            ->assertJsonMissing(['model' => 'PRIVATE']);
        $id = (int) $answer->json('data.id');
        $imageId = (int) $answer->json('data.attachments.0.id');

        $this->assertDatabaseHas('service_tickets', [
            'id' => $id, 'customer_id' => $customer->id,
            'origin' => 'customer_portal', 'status' => 'triage',
        ]);
        $this->assertDatabaseCount('service_ticket_printers', 2);
        $this->assertDatabaseCount('service_ticket_attachments', 2);
        $stored = DB::table('service_ticket_attachments')->where('ticket_id', $id)->pluck('storage_path');
        foreach ($stored as $path) {
            $this->assertStringStartsWith('tickets/'.$tenant->id.'/'.$id.'/', $path);
            Storage::disk('local')->assertExists($path);
        }
        $this->getJson('/api/v1/browser/client/tickets')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/browser/client/tickets/'.$id)->assertOk()
            ->assertJsonCount(2, 'data.attachments');
        $this->get('/api/v1/browser/client/tickets/'.$id.'/attachments/'.$imageId)
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/browser/tickets/'.$id)->assertForbidden();
        $this->postJson('/api/v1/browser/client/tickets', [
            'category' => 'outro',
            'description' => 'Tentativa de acesso a outras máquinas.',
            'printer_ids' => [$p1, $secret],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('service_tickets', 1);

        DB::table('customer_user_access')->where('user_id', $user->id)->delete();
        $this->getJson('/api/v1/browser/client/tickets/'.$id)->assertNotFound();
        $this->get('/api/v1/browser/client/tickets/'.$id.'/attachments/'.$imageId)->assertNotFound();
        $this->getJson('/api/v1/browser/client/tickets')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_untrusted_uploads_and_forged_scopes_are_rejected_without_any_file(): void
    {
        Storage::fake('local');
        $tenant = $this->newTenant('validation-tickets');
        $customer = $tenant->customers()->create(['name' => 'Client']);
        $id = $this->printer($tenant, $customer->id, 'MFC-J');
        $user = $this->access($tenant, $customer->id);
        $this->login($user, $tenant);

        $base = [
            'category' => 'outro',
            'description' => 'Uma descrição válida e completa.',
            'printer_ids' => [$id],
        ];
        $this->post('/api/v1/browser/client/tickets', [
            ...$base,
            'attachments' => [UploadedFile::fake()->create('script.php', 2, 'text/plain')],
        ], ['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/browser/client/tickets', [
            ...$base,
            'attachments' => [UploadedFile::fake()->create('video.mp4', 51201, 'video/mp4')],
        ], ['Accept'=>'application/json'])->assertUnprocessable();
        $this->postJson('/api/v1/browser/client/tickets', [
            ...$base, 'category' => 'unauthorized_status',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('service_tickets', 0);
        $this->assertDatabaseCount('service_ticket_attachments', 0);

        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $all = $this->access($tenant, $customer->id, 'customer', 'all');
        $this->login($all, $tenant);
        $this->getJson('/api/v1/browser/client/tickets')->assertForbidden();
        $this->postJson('/api/v1/browser/client/tickets', $base)->assertForbidden();
    }

    public function test_other_tenant_and_internal_role_cannot_read_ticket_or_media(): void
    {
        Storage::fake('local');
        $owner = $this->newTenant('attachments-owner');
        $c = $owner->customers()->create(['name' => 'Only client']);
        $id = $this->printer($owner, $c->id, 'Test');
        $user = $this->access($owner, $c->id);
        $this->login($user, $owner);
        $new = $this->post('/api/v1/browser/client/tickets', [
            'category' => 'manchas',
            'description' => 'A máquina imprime manchas na página.',
            'printer_ids' => [$id],
            'attachments' => [UploadedFile::fake()->image('manchas.png')],
        ], ['Accept'=>'application/json'])->assertCreated()->json('data');
        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();

        $foreign = $this->newTenant('attachments-foreign');
        $other = $foreign->customers()->create(['name' => 'Outsider']);
        $foreignUser = $this->access($foreign, $other->id);
        $this->login($foreignUser, $foreign);
        $this->getJson('/api/v1/browser/client/tickets/'.$new['id'])->assertNotFound();
        $this->get('/api/v1/browser/client/tickets/'.$new['id'].'/attachments/'.$new['attachments'][0]['id'])
            ->assertNotFound();
        $this->getJson('/api/v1/browser/client/tickets')->assertOk()->assertJsonCount(0, 'data');
    }
}
