<?php

namespace App\Http\Requests;

use App\Models\ModerationReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(ModerationReport::targetTypes())],
            'target_id' => ['required', 'integer', 'min:1'],
            'category' => ['required', Rule::in(ModerationReport::categories())],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
