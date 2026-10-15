<?php
declare(strict_types=1);

namespace App\Services;

use App\Mail\CollectorHealthIncidentMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Optional Resend API adapter: captures provider-generated email UUID for
 * *exact* webhook correlation. Other configured Laravel mail transports
 * remain supported but cannot be correlated to provider webhooks.
 */
final class CollectorMailTransport
{
    public function send(string $recipient,CollectorHealthIncidentMail $mail,string $attemptKey): ?string
    {
        if (config('nexa.collector_notifications.driver','laravel')!=='resend') {
            Mail::to($recipient)->send($mail);
            return null;
        }
        $apiKey=config('nexa.collector_notifications.resend_api_key');
        $from=config('mail.from.address');
        $fromName=config('mail.from.name');
        if (!is_string($apiKey) || !str_starts_with($apiKey,'re_')
            || !is_string($from) || !filter_var($from,FILTER_VALIDATE_EMAIL)
            || !filter_var($recipient,FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Resend transport not provisioned');
        }
        $fromFormatted=(is_string($fromName) && trim($fromName)!=='')
            ? trim(str_replace(["\r","\n","<",">"],'',$fromName)).' <'.$from.'>'
            : $from;

        $response=Http::withToken($apiKey)->acceptJson()->timeout(15)
            ->withHeaders(['Idempotency-Key'=>$attemptKey])
            ->post('https://api.resend.com/emails',[
                'from'=>$fromFormatted,
                'to'=>[$recipient],
                'subject'=>$mail->envelope()->subject,
                'text'=>view('emails.collector-health-incident',[
                    'tenantName'=>$mail->tenantName,
                    'customerName'=>$mail->customerName,
                    'collectorNumber'=>$mail->collectorNumber,
                    'incidentNumber'=>$mail->incidentNumber,
                    'lastHeartbeatUtc'=>$mail->lastHeartbeatUtc,
                    'detectedAtUtc'=>$mail->detectedAtUtc,
                    'stage'=>$mail->stage,
                ])->render(),
            ]);
        if (!$response->successful() || !is_string($response->json('id'))
            || !preg_match('/^[a-fA-F0-9-]{36}$/D',$response->json('id'))) {
            // Never log provider response bodies, authorization tokens or
            // recipient addresses. An ambiguous send cannot be auto-retried.
            throw new \RuntimeException('Provider did not confirm a correlated email identifier');
        }
        return strtolower($response->json('id'));
    }
}
