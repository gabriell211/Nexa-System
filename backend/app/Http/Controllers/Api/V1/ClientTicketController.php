<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ServiceTicket;
use App\Models\ServiceTicketAttachment;
use App\Services\AuditWriter;
use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer-facing ticket operations. Always enforce membership on each request,
 * independently of client-provided IDs or front-end navigation state.
 */
final class ClientTicketController extends Controller
{
    private const TYPES = [
        'gaveta_quebrada' => 'Gaveta quebrada',
        'painel_atolado' => 'Impressora atolando papel',
        'barulho' => 'Impressora fazendo muito barulho',
        'manchas' => 'Impressora manchando as folhas',
        'toner_reserva' => 'Solicitação de toner reserva',
        'outro' => 'Outro problema com impressora',
    ];
    private const MAX_TOTAL_BYTES = 104857600; // 100 MiB request quota

    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly AuditWriter $audit,
    ) {}

    private function client(Request $request): void
    {
        if ($request->attributes->get('nexa_role') !== 'customer'
            || $request->attributes->get('nexa_customer_scope') !== 'assigned') {
            abort(403, 'Acesso ao portal de chamados não autorizado.');
        }
    }

    private function permitted(Request $request)
    {
        $tenant = $request->attributes->get('nexa_tenant');
        return DB::table('customer_user_access')->select('customer_id')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id);
    }

    private function visible(Request $request, int $ticket): ServiceTicket
    {
        $this->client($request);
        return ServiceTicket::query()
            ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
            ->whereIn('customer_id', $this->permitted($request))
            ->findOrFail($ticket);
    }

    private function output(ServiceTicket $ticket): array
    {
        $ticket->loadMissing(['linkedPrinters:id,manufacturer,model,serial_number,ip_address',
            'attachments:id,tenant_id,ticket_id,original_name,mime_type,bytes,created_at']);
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'printers' => $ticket->linkedPrinters->map(static fn ($printer): array => [
                'id' => $printer->id,
                'manufacturer' => $printer->manufacturer,
                'model' => $printer->model,
                'serial_number' => $printer->serial_number,
                'ip_address' => $printer->ip_address,
            ])->values()->all(),
            'attachments' => $ticket->attachments->map(static fn ($item): array => [
                'id' => $item->id,
                'name' => $item->original_name,
                'mime_type' => $item->mime_type,
                'size' => (int) $item->bytes,
            ])->values()->all(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->client($request);
        $data = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','between:1,50'],
        ]);
        $tenant = $request->attributes->get('nexa_tenant');
        $result = ServiceTicket::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('customer_id', $this->permitted($request))
            ->with(['linkedPrinters:id,manufacturer,model,serial_number,ip_address',
                'attachments:id,tenant_id,ticket_id,original_name,mime_type,bytes,created_at'])
            ->orderByDesc('id')->paginate($data['per_page'] ?? 20);
        return response()->json([
            'data' => $result->getCollection()->map(fn ($ticket): array => $this->output($ticket)),
            'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(),
            'total' => $result->total(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return response()->json(['data' => $this->output($this->visible($request, $ticket))])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $this->client($request);
        $data = $request->validate([
            'category' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(self::TYPES))],
            'description' => ['required', 'string', 'min:10', 'max:12000'],
            'printer_ids' => ['required', 'array', 'min:1', 'max:5'],
            'printer_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'attachments' => ['sometimes', 'array', 'max:4'],
            'attachments.*' => [
                'required', 'file', 'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm',
                'extensions:jpg,jpeg,png,webp,mp4,mov,webm',
            ],
        ]);

        $tenant = $request->attributes->get('nexa_tenant');
        $ids = array_map('intval', $data['printer_ids']);
        $printers = $tenant->printers()->whereIn('id', $ids)
            ->whereIn('customer_id', $this->permitted($request))
            ->where('active', true)->get(['id', 'customer_id']);
        if ($printers->count() !== count($ids) || $printers->pluck('customer_id')->unique()->count() !== 1) {
            throw ValidationException::withMessages([
                'printer_ids' => 'Selecione impressoras ativas de um único cliente autorizado.',
            ]);
        }
        $uploads = $request->file('attachments', []);
        $total = array_sum(array_map(static fn ($file): int => $file->getSize(), $uploads));
        if ($total > self::MAX_TOTAL_BYTES) {
            throw ValidationException::withMessages(['attachments' => 'O total dos arquivos não pode exceder 100 MB.']);
        }

        $paths = [];
        try {
            $ticket = DB::transaction(function () use ($request, $tenant, $data, $printers, $ids, $uploads, &$paths): ServiceTicket {
                $record = ServiceTicket::query()->create([
                    'tenant_id' => $tenant->id,
                    'customer_id' => $printers->first()->customer_id,
                    'printer_id' => $ids[0],
                    'opened_by_user_id' => $request->user()->id,
                    'assigned_user_id' => null,
                    'subject' => self::TYPES[$data['category']],
                    'description' => $data['description'],
                    'category' => $data['category'],
                    'priority' => 'normal',
                    'status' => 'triage',
                    'origin' => 'customer_portal',
                ]);
                foreach ($ids as $id) {
                    DB::table('service_ticket_printers')->insert([
                        'tenant_id' => $tenant->id,
                        'ticket_id' => $record->id,
                        'printer_id' => $id,
                    ]);
                }
                foreach ($uploads as $file) {
                    $path = 'tickets/'.$tenant->id.'/'.$record->id.'/'.Str::uuid().'.'.$file->extension();
                    $stored = Storage::disk('local')->putFileAs(
                        dirname($path), $file, basename($path),
                    );
                    if ($stored !== $path) {
                        throw new \RuntimeException('Não foi possível salvar o arquivo do chamado.');
                    }
                    $paths[] = $path;
                    ServiceTicketAttachment::query()->create([
                        'tenant_id' => $tenant->id,
                        'ticket_id' => $record->id,
                        'uploaded_by_user_id' => $request->user()->id,
                        'original_name' => mb_substr(preg_replace('/[^\pL\pN._ -]/u', '_', basename($file->getClientOriginalName())) ?: 'arquivo', 0, 180),
                        'storage_path' => $path,
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'bytes' => $file->getSize(),
                    ]);
                }
                $this->audit->write($tenant, $request->user(), 'ticket.created', $record, null, [
                    'customer_id' => $record->customer_id,
                    'printer_ids' => $ids, 'status' => 'triage',
                    'origin' => 'customer_portal',
                    'attachment_count' => count($paths),
                ]);
                return $record;
            }, 2);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            throw $e;
        }
        return response()->json(['data' => $this->output($ticket)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Download route shared with backoffice, but client access is rechecked on
     * every request. Always Content-Disposition: attachment + nosniff.
     */
    public function download(Request $request, int $ticket, int $attachment): StreamedResponse
    {
        $role = $request->attributes->get('nexa_role');
        if ($role === 'customer') {
            $record = $this->visible($request, $ticket);
        } else {
            if (!in_array($role, ['owner', 'admin', 'manager', 'supervisor', 'technician'], true)) {
                abort(403);
            }
            $record = ServiceTicket::query()
                ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->findOrFail($ticket);
            if ($record->customer_id !== null) {
                $this->portfolio->assertCustomer($request, (int) $record->customer_id);
            } else {
                $this->portfolio->assertFull($request);
            }
        }
        $file = ServiceTicketAttachment::query()
            ->where('tenant_id', $record->tenant_id)
            ->where('ticket_id', $record->id)
            ->findOrFail($attachment);
        if (!Storage::disk('local')->exists($file->storage_path)) {
            abort(404);
        }

        return Storage::disk('local')->download($file->storage_path, $file->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
