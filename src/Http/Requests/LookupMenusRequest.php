<?php

namespace App\Domain\Navigation\Http\Requests;

use App\Domain\Navigation\Support\MenuLayout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /navigation/lookups/menus` — Select2 lookup for the Dynamic Menu CRUD.
 *
 * Same query surface as the Identity lookups it moved from: `q`, `page`,
 * `per_page`, `ids[]`, plus `layout` and `exclude`.
 */
class LookupMenusRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
            'layout' => ['sometimes', 'string', Rule::in(MenuLayout::all())],
            'exclude' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('layout')) {
            $this->merge(['layout' => MenuLayout::default()]);
        }
    }

    public function searchTerm(): ?string
    {
        $q = $this->validated('q');

        if (! is_string($q)) {
            return null;
        }

        $trimmed = trim($q);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = $this->validated('ids');

        return is_array($ids) ? array_values($ids) : [];
    }

    public function page(): int
    {
        return max(1, (int) $this->validated('page', 1));
    }

    public function perPage(): int
    {
        return min(50, max(1, (int) $this->validated('per_page', config('lookup.per_page', 10))));
    }

    public function layout(): string
    {
        return (string) $this->validated('layout', MenuLayout::default());
    }

    public function excludeId(): ?string
    {
        $exclude = $this->validated('exclude');

        return is_string($exclude) && $exclude !== '' ? $exclude : null;
    }
}
