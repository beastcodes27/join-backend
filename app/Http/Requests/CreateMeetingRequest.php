<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:120'], 'max_participants' => ['sometimes', 'integer', 'min:2', 'max:'.config('meeting.max_participants')]];
    }
}
