<?php

namespace App\Http\Requests;

use App\Models\ProductReview;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reply' => ['required', 'string', 'max:'.ProductReview::MAX_REPLY_LENGTH],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reply'))) {
            $this->merge(['reply' => trim($this->input('reply'))]);
        }
    }
}
