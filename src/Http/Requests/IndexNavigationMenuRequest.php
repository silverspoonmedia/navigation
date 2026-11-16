<?php

namespace App\Domain\Navigation\Http\Requests;

use App\Domain\Navigation\Support\MenuLayout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexNavigationMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layout' => ['sometimes', 'string', Rule::in(MenuLayout::all())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('q') === '') {
            $this->merge(['q' => null]);
        }

        if (! $this->has('layout')) {
            $this->merge(['layout' => MenuLayout::default()]);
        }
    }
}
