<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\StockPurchaseOrderLine;
use Illuminate\Support\Collection;

/**
 * Read-only operational figures derived from immutable receipt matches and
 * reviewed supplier returns. These are NOT on-hand inventory balances,
 * financial credit notes, or supplier-confirmed replacement orders.
 */
final class StockPurchaseFulfillment
{
    /** @return array<string,int> */
    public static function line(StockPurchaseOrderLine $line): array
    {
        $ordered = (int) $line->quantity;
        $gross = 0;
        $returned = 0;
        $pending = 0;

        foreach ($line->receiptMatches as $match) {
            $gross += (int) $match->quantity;
            foreach ($match->supplierReturns as $return) {
                if ($return->status === 'completed') {
                    $returned += (int) $return->quantity;
                } elseif ($return->status === 'pending') {
                    $pending += (int) $return->quantity;
                }
            }
        }

        $retained = max(0, $gross - $returned);
        $outstanding = max(0, $ordered - $retained);

        return [
            'ordered_quantity' => $ordered,
            'gross_received_quantity' => $gross,
            'returned_quantity' => $returned,
            'pending_return_quantity' => $pending,
            'retained_quantity' => $retained,
            'outstanding_quantity' => $outstanding,
            'replacement_needed_quantity' => min($returned, $outstanding),
        ];
    }

    /** @param Collection<int,array<string,int>> $lines
     *  @return array<string,int|string>
     */
    public static function order(Collection $lines): array
    {
        $keys = [
            'ordered_quantity','gross_received_quantity','returned_quantity',
            'pending_return_quantity','retained_quantity','outstanding_quantity',
            'replacement_needed_quantity',
        ];
        $summary = [];
        foreach ($keys as $key) {
            $summary[$key] = (int) $lines->sum(static fn (array $line): int => $line[$key]);
        }

        $summary['status'] = match (true) {
            $summary['pending_return_quantity'] > 0 => 'return_review_pending',
            $summary['replacement_needed_quantity'] > 0 => 'replacement_required',
            $summary['outstanding_quantity'] === 0 => 'net_received',
            $summary['gross_received_quantity'] === 0 => 'awaiting_receipt',
            default => 'partially_received',
        };

        return $summary;
    }
}
