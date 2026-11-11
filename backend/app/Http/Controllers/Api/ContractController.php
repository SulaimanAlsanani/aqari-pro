<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\InstallmentSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ContractController extends Controller
{
    private function owner(Request $request): Owner
    {
        return Owner::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    private function scopedContracts(Owner $owner)
    {
        return Contract::query()->whereHas('unit.property', fn ($query) => $query->where('owner_id', $owner->id));
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        return response()->json($this->scopedContracts($owner)
            ->with(['unit:id,property_id,code', 'tenant:id,name'])
            ->orderByDesc('id')->paginate(20));
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        $owner = $this->owner($request);
        $contract = $this->scopedContracts($owner)->whereKey($contract->id)
            ->with(['dues' => fn ($query) => $query->orderBy('due_date')->with(['payments' => fn ($payments) => $payments->orderBy('id')])])->firstOrFail();

        return response()->json(['data' => $contract]);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $data = $request->validate([
            'unit_id' => ['required', 'integer', 'min:1'],
            'tenant_id' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'total' => ['required', 'regex:/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/'],
            'installments' => ['required', 'integer', 'between:1,120'],
        ]);

        try {
            $schedule = InstallmentSchedule::make(
                $data['start_date'], $data['end_date'], (string) $data['total'], (int) $data['installments']
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['installments' => [$e->getMessage()]]);
        }

        $contract = DB::transaction(function () use ($owner, $data, $schedule) {
            // Serialize writers for this unit; locking the existing contract rows alone
            // would not protect the first insertion when no previous contract exists.
            $unit = Unit::query()->whereKey($data['unit_id'])->lockForUpdate()->firstOrFail();
            $property = Property::query()->whereKey($unit->property_id)->firstOrFail();
            abort_unless($property->owner_id === $owner->id, 404);

            $tenant = Tenant::query()->whereKey($data['tenant_id'])
                ->where('owner_id', $owner->id)->firstOrFail();

            $overlap = Contract::query()
                ->where('unit_id', $unit->id)
                ->whereDate('start_date', '<=', $data['end_date'])
                ->whereDate('end_date', '>=', $data['start_date'])
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['unit_id' => ['يوجد عقد متداخل على هذه الوحدة.']]);
            }

            $contract = Contract::query()->create([
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'total' => $data['total'],
                'installments' => $data['installments'],
                'status' => 'draft',
            ]);

            $contract->dues()->createMany($schedule);

            return $contract->load('dues');
        }, 3);

        return response()->json(['data' => $contract], 201);
    }
}
