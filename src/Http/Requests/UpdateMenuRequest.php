<?php

namespace App\Domain\Navigation\Http\Requests;

use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Http\Requests\Concerns\NormalizesMenuPlacementFields;
use App\Domain\Navigation\Http\Requests\Concerns\ValidatesMenuStructure;
use App\Domain\Navigation\Models\Menu;
use App\Support\Localization\Concerns\ValidatesTranslatableInput;
use App\Support\Localization\LocalePreference;
use App\Support\Navigation\SpaRouteRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateMenuRequest extends FormRequest
{
    use NormalizesMenuPlacementFields;
    use ValidatesMenuStructure;
    use ValidatesTranslatableInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePlacementFields();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $routeMenu = $this->route('menu');
        $menuId = $routeMenu instanceof Menu ? $routeMenu->getKey() : (string) $routeMenu;

        return [
            'name' => ['sometimes'],
            'description' => ['sometimes'],
            'type' => ['sometimes', 'string', 'size:1', Rule::in(['h', 'p', 'c'])],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'route' => ['sometimes', 'nullable', 'string', 'max:255', $this->namedRouteRule()],
            'hash' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parent' => ['sometimes', 'nullable', 'string', Rule::exists('menus', 'id')->whereNull('deleted_at'), Rule::notIn([$menuId])],
            'audience' => ['sometimes', new Enum(MenuAudience::class)],
            'position' => ['nullable', 'string', Rule::in(['before', 'after'])],
            'reference_id' => [
                Rule::excludeIf(fn () => ! in_array($this->input('position'), ['before', 'after'], true)),
                'required',
                'string',
                Rule::exists('menus', 'id')->whereNull('deleted_at'),
            ],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string'],
            'user_includes' => ['sometimes', 'array'],
            'user_includes.*' => ['string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'user_excludes' => ['sometimes', 'array'],
            'user_excludes.*' => ['string', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $supportedLocales = LocalePreference::supported();

            if ($this->has('name')) {
                $this->validateTranslatableString($validator, 'name', $supportedLocales);
                $this->validateTranslatableAllLocales($validator, 'name', $supportedLocales);
            }

            if ($this->has('description')) {
                $this->validateTranslatableString($validator, 'description', $supportedLocales);
                $this->validateTranslatableAllLocales($validator, 'description', $supportedLocales);
            }

            $menu = $this->route('menu');

            if (! $menu instanceof Menu) {
                return;
            }

            $audience = $this->effectiveAudience($menu->audience);

            $hasRoles = match (true) {
                $this->has('roles') => $this->input('roles', []) !== [],
                // Moving off the role audience drops the pivots in the service,
                // so the effective role set is empty even while pivots exist.
                $audience !== null && ! $audience->usesRoles() => false,
                default => $menu->roles()->exists(),
            };

            $this->assertAudienceCompatible($validator, $audience, $hasRoles);

            $changingType = $this->has('type');
            $changingParent = $this->has('parent');

            // Only enforce structure when the hierarchy is actually being changed,
            // so pure renames/reorders of pre-existing data are not rejected.
            if (! $changingType && ! $changingParent) {
                return;
            }

            $type = $changingType ? $this->input('type') : $menu->type;
            $parentId = $changingParent ? $this->input('parent') : $menu->parent_id;

            if ($changingParent) {
                $this->assertParentIsNotDescendant($validator, $menu->getKey(), $parentId);
                $this->assertParentLayoutMatches($validator, $menu->layout, $parentId);
            }

            if (is_string($type)) {
                $this->assertMenuStructure($validator, $type, $parentId, $menu->layout);
            }
        });
    }

    /**
     * The `route` column stores a SPA Vue Router symbolic name, not a URL.
     */
    private function namedRouteRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value) || ! SpaRouteRegistry::isValidMenuRoute($value)) {
                $fail(__('api/navigation.invalid_route'));
            }
        };
    }
}
