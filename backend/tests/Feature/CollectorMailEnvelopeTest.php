<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ReceiveCollectorMail;
use App\Models\CollectorAgent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class CollectorMailEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_envelope_authenticates_bound_active_collector_and_rejects_tampering(): void
    {
        $tenant = Tenant::create(['name' => 'Mail Test', 'slug' => 'mail-test']);
        $customer = $tenant->customers()->create(['name' => 'Unit']);
        $token = str_repeat('ab', 32);
        $agent = CollectorAgent::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'collector_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $token),
        ]);
        $nonce = random_bytes(12);
        $body = json_encode(['kind' => 'heartbeat', 'collector_id' => $agent->collector_id], JSON_THROW_ON_ERROR);
        $tag = '';
        $ciphertext = openssl_encrypt($body, 'aes-256-gcm', hash('sha256', $token, true),
            OPENSSL_RAW_DATA, $nonce, $tag, 'nexa-collector-mail-v1', 16);
        $envelope = [
            'version' => 1, 'collector_id' => $agent->collector_id,
            'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($ciphertext.$tag),
        ];
        $decrypt = new ReflectionMethod(ReceiveCollectorMail::class, 'decrypt');
        $command = app(ReceiveCollectorMail::class);
        $result = $decrypt->invoke($command, $envelope);
        $this->assertSame($agent->id, $result[0]->id);
        $this->assertSame('heartbeat', $result[1]['kind']);

        $tampered = $envelope;
        $tampered['ciphertext'] = base64_encode(str_repeat('x', strlen($ciphertext) + 16));
        $this->assertNull($decrypt->invoke($command, $tampered));

        $agent->update(['active' => false]);
        $this->assertNull($decrypt->invoke($command, $envelope));
    }
}
