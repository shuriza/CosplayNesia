<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\RentalReservation;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CheckoutRequest extends FormRequest
{
    public function prepareForValidation(): void
    {
        $recipient = $this->input('recipient', []);
        $address = $this->input('address', []);

        if (is_array($recipient)) {
            $recipient['name'] = trim((string) ($recipient['name'] ?? ''));
            $recipient['phone'] = $this->normalizePhone($recipient['phone'] ?? '');
            $recipient['email'] = mb_strtolower(trim((string) ($recipient['email'] ?? '')));
        }
        if (is_array($address)) {
            foreach (['line1', 'line2', 'city', 'province', 'postal_code'] as $field) {
                $address[$field] = trim((string) ($address[$field] ?? ''));
            }
        }

        $this->merge(['recipient' => $recipient, 'address' => $address]);
        if ($this->header('Idempotency-Key') !== null) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['required', 'integer', 'distinct', Rule::exists(Product::class, 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.end_date' => ['nullable', 'date_format:Y-m-d'],
            'recipient' => ['required', 'array'],
            'recipient.name' => ['required', 'string', 'min:2', 'max:80'],
            'recipient.phone' => ['required', 'string', 'max:24'],
            'recipient.email' => ['required', 'email:rfc', 'max:255'],
            'address' => ['required', 'array'],
            'address.line1' => ['required', 'string', 'min:5', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'min:2', 'max:80'],
            'address.province' => ['required', 'string', 'min:2', 'max:80'],
            'address.postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'handoff_note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->normalizePhone($this->input('recipient.phone', '')) === null) {
                $validator->errors()->add('recipient.phone', 'Masukkan nomor Indonesia yang valid, misalnya 081234567890 atau +62 812 3456 7890.');
            }
            $items = $this->input('items', []);
            $ids = collect($items)->pluck('id')->filter()->unique()->values();
            $types = Product::query()->whereIn('id', $ids)->pluck('type', 'id');
            $today = Carbon::today(config('app.timezone'));

            foreach ($items as $index => $item) {
                $type = $types->get($item['id'] ?? null);
                $start = $item['start_date'] ?? null;
                $end = $item['end_date'] ?? null;

                if ($type === 'Sewa') {
                    if (! $start) {
                        $validator->errors()->add("items.{$index}.start_date", 'Tanggal mulai wajib untuk produk sewa.');
                    }
                    if (! $end) {
                        $validator->errors()->add("items.{$index}.end_date", 'Tanggal selesai wajib untuk produk sewa.');
                    }
                    $this->validateRentalDates($validator, $index, $start, $end, $today);
                } elseif ($start || $end) {
                    $validator->errors()->add("items.{$index}.start_date", 'Tanggal sewa hanya dapat diisi untuk produk sewa.');
                }
            }
        });
    }

    private function normalizePhone(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || ! preg_match('/^\+?[0-9\s().-]+$/', $raw)) {
            return null;
        }

        $compact = preg_replace('/[\s().-]+/', '', $raw);
        if (! is_string($compact)) {
            return null;
        }
        if (str_starts_with($compact, '+62')) {
            $national = substr($compact, 3);
        } elseif (str_starts_with($compact, '0')) {
            $national = substr($compact, 1);
        } else {
            return null;
        }

        $canonical = '+62'.$national;

        return preg_match('/^\+62[2-9][0-9]{7,12}$/', $canonical) === 1 ? $canonical : null;
    }

    private function validateRentalDates(Validator $validator, int|string $index, mixed $start, mixed $end, Carbon $today): void
    {
        if (! is_string($start) || ! is_string($end)) {
            return;
        }

        try {
            $from = Carbon::createFromFormat('!Y-m-d', $start, config('app.timezone'));
            $to = Carbon::createFromFormat('!Y-m-d', $end, config('app.timezone'));
        } catch (\Throwable) {
            return;
        }

        if ($from->lt($today)) {
            $validator->errors()->add("items.{$index}.start_date", 'Tanggal mulai harus hari ini atau setelahnya.');
        }
        if ($to->lt($from)) {
            $validator->errors()->add("items.{$index}.end_date", 'Tanggal selesai harus setelah atau sama dengan tanggal mulai.');
        }
        if ($from->diffInDays($to) + 1 > RentalReservation::MAX_DAYS) {
            $validator->errors()->add("items.{$index}.end_date", 'Durasi sewa maksimal 30 hari secara inklusif.');
        }
    }
}
