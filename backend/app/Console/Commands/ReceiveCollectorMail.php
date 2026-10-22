<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\CollectorIngestRequest;
use App\Models\CollectorAgent;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CollectorReadingsIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/** Receives authenticated collector payloads through an explicitly configured POP3 inbox. */
final class ReceiveCollectorMail extends Command
{
    protected $signature = 'nexa:collector:receive-mail';
    protected $description = 'Receive encrypted collector readings and return persisted ACKs by email';
    private const AAD = 'nexa-collector-mail-v1';

    public function handle(CollectorReadingsIngestor $ingestor): int
    {
        $config = config('nexa.collector_mail_inbox');
        if (!$config['enabled']) {
            $this->info('Collector mail inbox disabled.');
            return self::SUCCESS;
        }
        if (!filter_var($config['address'], FILTER_VALIDATE_EMAIL)
            || $config['password'] === '' || $config['pop3_host'] === ''
            || preg_match('/[\x00-\x1F\x7F]/', $config['password'].$config['pop3_host'])
            || !preg_match('/^[A-Za-z0-9.-]+$/D', $config['pop3_host'])
            || $config['pop3_port'] < 1 || $config['pop3_port'] > 65535
            || in_array(config('mail.default'), ['log', 'array', null], true)
            || !filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            $this->error('Collector mail inbox or outbound mail transport is incomplete.');
            return self::FAILURE;
        }

        $socket = null;
        $processed = 0;
        $deferred = 0;
        try {
            $socket = $this->connect($config);
            $this->command($socket, 'USER '.$config['address']);
            $this->command($socket, 'PASS '.$config['password']);
            $listing = $this->command($socket, 'LIST', true);
            $ids = [];
            foreach ($listing as $line) {
                if (preg_match('/^(\d+)\s+\d+$/', $line, $matches)) {
                    $ids[] = (int) $matches[1];
                }
            }
            foreach (array_slice($ids, -100) as $id) {
                try {
                    $message = implode("\r\n", $this->command($socket, 'RETR '.$id, true));
                    $envelope = $this->messageEnvelope($message);
                    if ($envelope === null) { $deferred++; continue; }
                    $payload = $this->decrypt($envelope);
                    if ($payload === null) { $deferred++; continue; }
                    [$agent, $body] = $payload;
                    if ($body['kind'] === 'samples') {
                        $valid = Validator::make($body, (new CollectorIngestRequest)->rules())->validate();
                        if (!filter_var($body['reply_to'] ?? null, FILTER_VALIDATE_EMAIL)) {
                            throw new \RuntimeException('Invalid collector reply address');
                        }
                        $ack = $ingestor->ingest($agent, $valid['samples']);
                        $this->sendAck($agent, $body['reply_to'], $ack);
                    } elseif ($body['kind'] === 'heartbeat') {
                        $values = ['heartbeat_at' => now('UTC'), 'updated_at' => now('UTC')];
                        if (isset($body['watchdog'])) {
                            $watchdog = $body['watchdog'];
                            if (!is_array($watchdog) || !is_int($watchdog['recent_incidents'] ?? null)
                                || $watchdog['recent_incidents'] < 0 || $watchdog['recent_incidents'] > 32
                                || !is_bool($watchdog['circuit_open'] ?? null)) {
                                throw new \RuntimeException('Invalid watchdog status');
                            }
                            $values['watchdog_recent_incidents'] = $watchdog['recent_incidents'];
                            $values['watchdog_circuit_open_reported'] = $watchdog['circuit_open'];
                            $values['watchdog_reported_at'] = now('UTC');
                        }
                        if (DB::table('collector_agents')->where('id', $agent->id)->where('active', true)->update($values) !== 1) {
                            throw new \RuntimeException('Collector inactive');
                        }
                    } else {
                        throw new \RuntimeException('Unsupported collector mail payload');
                    }
                    $this->command($socket, 'DELE '.$id);
                    $processed++;
                } catch (\Throwable $error) {
                    // Keep the message for an operator or a later retry. Never log its body or credentials.
                    $deferred++;
                }
            }
            $this->command($socket, 'QUIT');
            fclose($socket);
            $socket = null;
        } catch (\Throwable $error) {
            $this->error('Collector mailbox connection or protocol failed; messages remain on server.');
            return self::FAILURE;
        } finally {
            if (is_resource($socket)) { fclose($socket); }
        }
        $this->info("Collector mail: processed={$processed}, deferred={$deferred}.");
        return $deferred === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return resource */
    private function connect(array $config)
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => $config['pop3_host'],
        ]]);
        $scheme = $config['pop3_implicit_tls'] ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            $scheme.'://'.$config['pop3_host'].':'.$config['pop3_port'],
            $number, $message, 15, STREAM_CLIENT_CONNECT, $context,
        );
        if (!is_resource($socket)) { throw new \RuntimeException('POP3 unavailable'); }
        stream_set_timeout($socket, 15);
        $this->response($socket);
        if (!$config['pop3_implicit_tls']) {
            $this->command($socket, 'STLS');
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                fclose($socket);
                throw new \RuntimeException('POP3 TLS upgrade failed');
            }
        }
        return $socket;
    }

    /** @param resource $socket
     *  @return array<int, string>
     */
    private function command($socket, string $command, bool $multiline = false): array
    {
        if (fwrite($socket, $command."\r\n") === false) { throw new \RuntimeException('POP3 write failed'); }
        $this->response($socket);
        if (!$multiline) { return []; }
        $lines = [];
        $bytes = 0;
        while (true) {
            $line = fgets($socket, 65537);
            if ($line === false || strlen($line) > 65536) { throw new \RuntimeException('POP3 read failed'); }
            $line = rtrim($line, "\r\n");
            if ($line === '.') { break; }
            if (str_starts_with($line, '..')) { $line = substr($line, 1); }
            $bytes += strlen($line) + 2;
            if ($bytes > 300000) { throw new \RuntimeException('Collector mail exceeds size limit'); }
            $lines[] = $line;
        }
        return $lines;
    }

    /** @param resource $socket */
    private function response($socket): void
    {
        $line = fgets($socket, 4096);
        if (!is_string($line) || !str_starts_with($line, '+OK')) {
            throw new \RuntimeException('POP3 rejected command');
        }
    }

    private function messageEnvelope(string $message): ?array
    {
        $parts = preg_split('/\r?\n\r?\n/', $message, 2);
        if (count($parts) !== 2 || strlen($parts[1]) > 280000) { return null; }
        // The collector emits a single plain-text MIME part with 76-character base64 lines.
        $json = base64_decode(preg_replace('/\s+/', '', $parts[1]), true);
        $value = is_string($json) ? json_decode($json, true) : null;
        return is_array($value) ? $value : null;
    }

    /** @return array{0: CollectorAgent, 1: array}|null */
    private function decrypt(array $envelope): ?array
    {
        if (($envelope['version'] ?? null) !== 1 || !is_string($envelope['collector_id'] ?? null)) { return null; }
        $agent = CollectorAgent::query()->where('collector_id', $envelope['collector_id'])->where('active', true)->first();
        if ($agent === null || !Tenant::query()->whereKey($agent->tenant_id)->where('active', true)->exists()
            || !Customer::query()->whereKey($agent->customer_id)->where('tenant_id', $agent->tenant_id)->where('active', true)->exists()) {
            return null;
        }
        $nonce = base64_decode((string) ($envelope['nonce'] ?? ''), true);
        $encrypted = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);
        $key = hex2bin($agent->token_hash);
        if ($key === false || strlen($key) !== 32 || $nonce === false || strlen($nonce) !== 12
            || $encrypted === false || strlen($encrypted) < 17) { return null; }
        $body = openssl_decrypt(substr($encrypted, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            $nonce, substr($encrypted, -16), self::AAD);
        $payload = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($payload) || ($payload['collector_id'] ?? null) !== $agent->collector_id) { return null; }
        return [$agent, $payload];
    }

    /** @param array<int, string> $ack */
    private function sendAck(CollectorAgent $agent, string $recipient, array $ack): void
    {
        $key = hex2bin($agent->token_hash);
        $nonce = random_bytes(12);
        $tag = '';
        $plain = json_encode(['kind' => 'ack', 'collector_id' => $agent->collector_id,
            'acknowledged' => $ack], JSON_THROW_ON_ERROR);
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            $nonce, $tag, self::AAD, 16);
        if (!is_string($ciphertext)) { throw new \RuntimeException('ACK encryption failed'); }
        $envelope = json_encode(['version' => 1, 'collector_id' => $agent->collector_id,
            'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($ciphertext.$tag)], JSON_THROW_ON_ERROR);
        $body = chunk_split(base64_encode($envelope), 76, "\r\n");
        Mail::raw($body, function ($mail) use ($recipient): void {
            $mail->to($recipient)->subject('Nexa Collector ACK');
        });
    }
}
