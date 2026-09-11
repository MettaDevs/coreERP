<?php

namespace App\Http\Requests\NumberSequence;

use App\Actions\NumberSequence\NumberSequenceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NumberSequenceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-number-sequences') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'profile_code' => ['required', 'string', 'exists:number_sequence_profiles,code'],
            'scope_type' => ['required', Rule::in(['tenant', 'legal_entity', 'operating_unit'])],
            'status' => ['required', Rule::in(['draft', 'active', 'stopped'])],
            'is_continuous' => ['required', 'boolean'],
            'allow_manual' => ['required', 'boolean'],
            'reset_period' => ['required', Rule::in(NumberSequenceService::RESET_PERIODS)],
            'preallocation_enabled' => ['required', 'boolean'],
            'preallocation_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'minimum_number' => ['required', 'integer', 'min:0'],
            'maximum_number' => ['nullable', 'integer', 'min:0'],
            'segments' => ['required', 'array', 'min:1', 'max:10'],
            'segments.*.type' => ['required', Rule::in(NumberSequenceService::SEGMENT_TYPES)],
            'segments.*.value' => ['nullable', 'string', 'max:80'],
            'segments.*.length' => ['nullable', 'integer', 'min:1', 'max:18'],
        ];
    }

    /** @return array{profile_code:string,scope_type:string,status:string,is_continuous:bool,allow_manual:bool,reset_period:string,preallocation_enabled:bool,preallocation_quantity:int,minimum_number:int,maximum_number:?int,segments:list<array<string,mixed>>} */
    public function payload(): array
    {
        return [
            'profile_code' => $this->string('profile_code')->toString(),
            'scope_type' => $this->string('scope_type')->toString(),
            'status' => $this->string('status')->toString(),
            'is_continuous' => $this->boolean('is_continuous'),
            'allow_manual' => $this->boolean('allow_manual'),
            'reset_period' => $this->string('reset_period')->toString(),
            'preallocation_enabled' => $this->boolean('preallocation_enabled'),
            'preallocation_quantity' => $this->integer('preallocation_quantity'),
            'minimum_number' => $this->integer('minimum_number'),
            'maximum_number' => $this->input('maximum_number') === null ? null : $this->integer('maximum_number'),
            'segments' => array_values($this->input('segments')),
        ];
    }
}
