<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    private function owner(Request $request): Owner
    {
        return Owner::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->owner($request)->tenants()->orderBy('id')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json(['data' => $owner->tenants()->create($data)], 201);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $owner = $this->owner($request);
        abort_unless($tenant->owner_id === $owner->id, 404);

        return response()->json(['data' => $tenant]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $owner = $this->owner($request);
        abort_unless($tenant->owner_id === $owner->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $tenant->update($data);

        return response()->json(['data' => $tenant->refresh()]);
    }
}
