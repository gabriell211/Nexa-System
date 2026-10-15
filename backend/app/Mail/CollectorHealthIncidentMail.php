<?php
declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class CollectorHealthIncidentMail extends Mailable
{
    public function __construct(
        public readonly string $tenantName,
        public readonly string $customerName,
        public readonly int $collectorNumber,
        public readonly int $incidentNumber,
        public readonly string $lastHeartbeatUtc,
        public readonly string $detectedAtUtc,
        public readonly string $stage
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->stage==='escalation'
                ? '[Nexa] Escalonamento: heartbeat do Collector em atraso'
                : '[Nexa] Collector sem heartbeat recente'
        );
    }

    public function content(): Content
    {
        return new Content(text:'emails.collector-health-incident');
    }
}
