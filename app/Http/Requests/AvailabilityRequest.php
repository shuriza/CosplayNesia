<?php

namespace App\Http\Requests;

use App\Models\RentalReservation;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('start_date');
            $end = $this->input('end_date');
            if (! is_string($start) || ! is_string($end)) {
                return;
            }

            try {
                $from = Carbon::createFromFormat('!Y-m-d', $start, config('app.timezone'));
                $to = Carbon::createFromFormat('!Y-m-d', $end, config('app.timezone'));
            } catch (\Throwable) {
                return;
            }

            $today = Carbon::today(config('app.timezone'));
            if ($from->lt($today)) {
                $validator->errors()->add('start_date', 'Tanggal mulai harus hari ini atau setelahnya.');
            }
            if ($to->lt($from)) {
                $validator->errors()->add('end_date', 'Tanggal selesai harus setelah atau sama dengan tanggal mulai.');
            }
            if ($from->diffInDays($to) + 1 > RentalReservation::MAX_DAYS) {
                $validator->errors()->add('end_date', 'Durasi sewa maksimal 30 hari secara inklusif.');
            }
        });
    }
}
