<?php

namespace App\Domain\Navigation\Http\Requests;

use App\Domain\Navigation\Support\MenuLayout;
use App\Http\Requests\Concerns\InteractsWithListDisplayMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMenuListRequest extends FormRequest
{
    use InteractsWithListDisplayMode;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge($this->listDisplayModeRules(), [
            'load_type' => ['sometimes', 'nullable', 'string', Rule::in(['h', 'p', 'c'])],
            'layout' => ['sometimes', 'string', Rule::in(MenuLayout::all())],
        ]);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('layout')) {
            $this->merge(['layout' => MenuLayout::default()]);
        }
    }
}
