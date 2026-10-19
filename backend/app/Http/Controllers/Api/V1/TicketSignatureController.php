<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ServiceTicket;
use App\Models\ServiceTicketSignature;
use App\Services\CustomerPortfolio;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
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
        if (!Storage::disk('local')->exists($signature->storage_path)) abort(404);
        return Storage::disk('local')->download($signature->storage_path,'assinatura-'.$ticket.'.png',[
            'Content-Type'=>'image/png','X-Content-Type-Options'=>'nosniff',
            'Cache-Control'=>'no-store, private',
        ]);
    }
}
