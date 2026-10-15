<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\ResendWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ResendWebhookController extends Controller
{
    public function __invoke(Request $request,ResendWebhookProcessor $processor): JsonResponse
    {
        // Authentication is mandatory before any attempt to parse event data.
        // Webhooks are not authenticated as tenant users or collectors.
        if (!config('nexa.collector_notifications.resend_webhooks_enabled')
            || config('nexa.collector_notifications.driver')!=='resend') {
            return response()->json(['error'=>'not_configured'],404);
        }
        $body=$request->getContent();
        $id=$request->header('svix-id');
        if (!$processor->verify(
            $body,$id,$request->header('svix-timestamp'),
            $request->header('svix-signature')
        )) {
            return response()->json(['error'=>'invalid_signature'],401);
        }
        $result=$processor->ingest($body,$id);
        if ($result==='invalid') {
            return response()->json(['error'=>'invalid_event'],422);
        }
        return response()->json(['acknowledged'=>true,'result'=>$result]);
    }
}
