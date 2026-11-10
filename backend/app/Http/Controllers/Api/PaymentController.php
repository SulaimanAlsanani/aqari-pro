<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Due;
use App\Models\Owner;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    private function owner(Request $request): Owner
    {
        return Owner::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    // Keep financial calculations in integer halalas, never PHP binary floats.
    private function cents(string $value): int
    {
        if (!preg_match('/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => ['المبلغ غير صالح.']]);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function scopedDues(Owner $owner)
    {
        return Due::query()->whereHas('contract.unit.property', function ($query) use ($owner) {
            $query->where('owner_id', $owner->id);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        return response()->json(
            Payment::query()
                ->whereHas('due.contract.unit.property', fn ($query) => $query->where('owner_id', $owner->id))
                ->orderByDesc('id')->paginate(20)
        );
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $owner = $this->owner($request);

        $record = Payment::query()
            ->whereKey($payment->id)
            ->whereHas('due.contract.unit.property', fn ($query) => $query->where('owner_id', $owner->id))
            ->with(['due.contract.tenant', 'due.contract.unit.property'])
            ->firstOrFail();

        return response()->json(['data' => $record]);
    }

    public function store(Request $request, Due $due): JsonResponse
    {
        $owner = $this->owner($request);

        $data = $request->validate([
            'amount' => ['required', 'regex:/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/'],
            'received_at' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'string', 'max:60'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $amountCents = $this->cents((string) $data['amount']);
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount' => ['المبلغ يجب أن يكون أكبر من صفر.']]);
        }

        $payment = DB::transaction(function () use ($owner, $due, $data, $amountCents) {
            // Lock the due before computing the balance. Concurrent requests
            // for one installment are serialized on PostgreSQL.
            $locked = $this->scopedDues($owner)
                ->whereKey($due->id)
                ->lockForUpdate()->firstOrFail();

            $existing = Payment::query()->where('due_id', $locked->id)->get();

            $reference = trim((string) ($data['reference'] ?? ''));
            $method = trim($data['method']);

            if ($reference !== '' && $existing->contains(fn ($payment) =>
                $payment->method === $method &&
                mb_strtolower(trim((string) $payment->reference)) === mb_strtolower($reference)
            )) {
                throw ValidationException::withMessages([
                    'reference' => ['مرجع الدفع مستخدم مسبقًا لهذا الاستحقاق وطريقة السداد.'],
                ]);
            }

            $receivedCents = 0;
            foreach ($existing as $record) {
                $receivedCents += $this->cents((string) $record->amount);
            }

            if ($receivedCents + $amountCents > $this->cents((string) $locked->amount)) {
                throw ValidationException::withMessages(['amount' => ['المبلغ يتجاوز الرصيد المتبقي.']]);
            }

            return $locked->payments()->create([
                'amount' => $data['amount'],
                'received_at' => $data['received_at'],
                'method' => $method,
                'reference' => $reference !== '' ? $reference : null,
            ]);
        }, 3);

        return response()->json(['data' => $payment], 201);
    }
}
