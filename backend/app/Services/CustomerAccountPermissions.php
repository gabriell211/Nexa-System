<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Server-side permission lookup: absence of a row preserves legacy customer
 * behavior; freshly invited accounts always have an explicit settings row. */
final class CustomerAccountPermissions
{
    public function forRequest(Request $request): array
    {
        if ($request->attributes->get('nexa_role') !== 'customer') {
            return ['view_reports' => false, 'view_other_tickets' => false];
        }
        $tenant = $request->attributes->get('nexa_tenant');
        $prefs = DB::table('customer_account_settings')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)->first();
        return [
            'view_reports' => $prefs === null ? true : (bool) $prefs->view_reports,
            'view_other_tickets' => $prefs === null ? true : (bool) $prefs->view_other_tickets,
        ];
    }
}
