<?php

namespace App\Http\Controllers;

use App\Models\BuyerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuyerAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->buyerAddresses()->orderByDesc('is_default')->latest('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $address = DB::transaction(function () use ($request): BuyerAddress {
            $data = $request->validate($this->rules());
            $user = $request->user();
            $data['is_default'] = (bool) ($data['is_default'] ?? ! $user->buyerAddresses()->exists());
            if ($data['is_default']) {
                $user->buyerAddresses()->update(['is_default' => false]);
            }

            return $user->buyerAddresses()->create($data);
        });

        return response()->json(['data' => $address], 201);
    }

    public function update(Request $request, BuyerAddress $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($request, $address): void {
            $data = $request->validate($this->rules());
            if ((bool) ($data['is_default'] ?? false)) {
                $request->user()->buyerAddresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            }
            $address->update($data);
        });

        return response()->json(['data' => $address->refresh()]);
    }

    public function destroy(Request $request, BuyerAddress $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($request, $address): void {
            $wasDefault = $address->is_default;
            $address->delete();
            if ($wasDefault) {
                $request->user()->buyerAddresses()->latest('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(status: 204);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:40'],
            'recipient_name' => ['required', 'string', 'max:80'],
            'recipient_phone' => ['required', 'string', 'regex:/^\\+?[0-9][0-9 -]{7,22}$/'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'province' => ['required', 'string', 'max:80'],
            'postal_code' => ['required', 'digits:5'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
