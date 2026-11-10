<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Due;
use App\Models\Owner;
use App\Models\Property;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceivablesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $owner = Owner::query()->where('user_id', $request->user()->getAuthIdentifier())->firstOrFail();
        $data = $request->validate([
            'property_id' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);

        $propertyId = $data['property_id'] ?? null;
        if ($propertyId !== null) {
            Property::query()->where('owner_id', $owner->id)->whereKey($propertyId)->firstOrFail();
        }

        $today = CarbonImmutable::today();
        $totals = [
            'due' => 0, 'collected' => 0, 'outstanding' => 0,
            'overdue' => 0, 'not_due' => 0, 'days_1_30' => 0,
            'days_31_60' => 0, 'days_61_90' => 0, 'days_91_plus' => 0,
        ];
        $count = 0;

        Due::query()
            ->whereHas('contract.unit.property', function ($query) use ($owner, $propertyId) {
                $query->where('owner_id', $owner->id);
                if ($propertyId !== null) {
                    $query->whereKey($propertyId);
                }
            })
            ->with(['payments' => fn ($query) => $query->whereNull('reversed_at')])
            ->chunkById(200, function ($dues) use ($today, &$totals, &$count): void {
                foreach ($dues as $due) {
                    $count++;
                    $amount = Money::cents((string) $due->amount);
                    $paid = 0;
                    foreach ($due->payments as $payment) {
                        $paid += Money::cents((string) $payment->amount);
                    }
                    $remaining = max(0, $amount - $paid);
                    $totals['due'] += $amount;
                    $totals['collected'] += $paid;
                    $totals['outstanding'] += $remaining;

                    if ($remaining === 0) {
                        continue;
                    }
                    $date = CarbonImmutable::parse($due->due_date->format('Y-m-d'));
                    if ($date->greaterThanOrEqualTo($today)) {
                        $totals['not_due'] += $remaining;
                        continue;
                    }

                    $totals['overdue'] += $remaining;
                    $days = $date->diffInDays($today);
                    $key = $days <= 30 ? 'days_1_30'
                        : ($days <= 60 ? 'days_31_60'
                        : ($days <= 90 ? 'days_61_90' : 'days_91_plus'));
                    $totals[$key] += $remaining;
                }
            });

        return response()->json([
            'as_of' => $today->format('Y-m-d'),
            'currency' => 'SAR',
            'property_id' => $propertyId,
            'installments_count' => $count,
            'amounts' => array_map(fn (int $cents) => Money::format($cents), $totals),
        ]);
    }
}
