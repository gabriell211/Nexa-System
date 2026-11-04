<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ServiceTicket;
use App\Models\ServiceTicketSignature;
use App\Services\CustomerPortfolio;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TicketSignatureController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function download(Request $request,int $ticket): StreamedResponse
    {
        $tenant=$request->attributes->get('nexa_tenant');
        $record=ServiceTicket::query()->where('tenant_id',$tenant->id)->findOrFail($ticket);
        if ($record->customer_id!==null) {
            $this->portfolio->assertCustomer($request,(int)$record->customer_id);
        } else {
            $this->portfolio->assertFull($request);
        }
        $signature=ServiceTicketSignature::query()->where('tenant_id',$tenant->id)
            ->where('ticket_id',$ticket)->firstOrFail();
        if (is_string($signature->png_ciphertext) && $signature->png_ciphertext !== '') {
            try {
                $decoded = base64_decode(Crypt::decryptString($signature->png_ciphertext), true);
            } catch (DecryptException) {
                abort(503, 'Evidência de assinatura indisponível.');
            }
            if (!is_string($decoded) || !hash_equals((string) $signature->sha256, hash('sha256', $decoded))) {
                abort(503, 'Integridade da assinatura não confirmada.');
            }
            return response()->streamDownload(
                static function () use ($decoded): void { echo $decoded; },
                'assinatura-'.$ticket.'.png',
                ['Content-Type'=>'image/png', 'X-Content-Type-Options'=>'nosniff',
                 'Cache-Control'=>'no-store, private'],
            );
        }
        // Compatibility for old on-premises signatures only.
        if (!Storage::disk('local')->exists($signature->storage_path)) abort(404);
        return Storage::disk('local')->download($signature->storage_path,'assinatura-'.$ticket.'.png',[
            'Content-Type'=>'image/png','X-Content-Type-Options'=>'nosniff',
            'Cache-Control'=>'no-store, private',
        ]);
    }
}
