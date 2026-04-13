<?php

namespace App\Http\Requests;

use App\Enums\ThoughtLinkType;
use App\Models\Thought;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreThoughtLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thought = $this->route('thought');

        return $thought instanceof Thought
            && $this->user() !== null
            && (int) $thought->user_id === (int) $this->user()->id;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var Thought $from */
        $from = $this->route('thought');

        return [
            'to_thought_id' => [
                'required',
                'uuid',
                Rule::exists('thoughts', 'id')->where('user_id', $this->user()->id),
                Rule::notIn([$from->id]),
            ],
            'link_type' => [
                'required',
                Rule::enum(ThoughtLinkType::class),
                Rule::unique('thought_links', 'link_type')
                    ->where('from_thought_id', $from->id)
                    ->where('to_thought_id', (string) $this->input('to_thought_id')),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
