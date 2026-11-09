<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    private function owner(Request $request): Owner
    {
        return Owner::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        return response()->json(
            Property::query()
                ->where('owner_id', $owner->id)
                ->orderBy('id')
                ->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:60'],
        ]);

        // Owner ID is assigned server-side, never accepted from the client.
        $property = $owner->properties()->create($data);

        return response()->json(['data' => $property], 201);
    }

    public function show(Request $request, Property $property): JsonResponse
    {
        $owner = $this->owner($request);
        abort_unless($property->owner_id === $owner->id, 404);

        return response()->json(['data' => $property]);
    }

    public function update(Request $request, Property $property): JsonResponse
    {
        $owner = $this->owner($request);
        abort_unless($property->owner_id === $owner->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'district' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $property->update($data);

        return response()->json(['data' => $property->refresh()]);
    }
}
