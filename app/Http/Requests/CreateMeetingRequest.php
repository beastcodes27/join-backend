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
        return [
            'title' => ['required', 'string', 'max:120'],
            'max_participants' => ['sometimes', 'integer', 'min:2', 'max:'.config('meeting.max_participants')],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addYear()->toIso8601String(), 'regex:/T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/'],
            'timezone' => ['required_with:scheduled_at', 'timezone'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:'.config('meeting.max_minutes')],
        ];
    }
}
