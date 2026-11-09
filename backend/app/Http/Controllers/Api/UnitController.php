<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    private function propertyForOwner(Request $request, Property $property): Property
    {
        $owner = Owner::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();

        abort_unless($property->owner_id === $owner->id, 404);

        return $property;
    }

    public function index(Request $request, Property $property): JsonResponse
    {
        $property = $this->propertyForOwner($request, $property);

        return response()->json($property->units()->orderBy('id')->paginate(20));
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        $property = $this->propertyForOwner($request, $property);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('units')->where('property_id', $property->id)],
            'type' => ['nullable', 'string', 'max:60'],
            'area' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        // The property association is imposed by the authorized parent.
        return response()->json(['data' => $property->units()->create($data)], 201);
    }

    public function show(Request $request, Property $property, Unit $unit): JsonResponse
    {
        $this->propertyForOwner($request, $property);
        abort_unless($unit->property_id === $property->id, 404);

        return response()->json(['data' => $unit]);
    }

    public function update(Request $request, Property $property, Unit $unit): JsonResponse
    {
        $this->propertyForOwner($request, $property);
        abort_unless($unit->property_id === $property->id, 404);

        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:60',
                Rule::unique('units')->where('property_id', $property->id)->ignore($unit->id)],
            'type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'area' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $unit->update($data);

        return response()->json(['data' => $unit->refresh()]);
    }
}
