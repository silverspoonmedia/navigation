<?php

namespace App\Domain\Navigation\Http\Requests\Concerns;

/**
 * Placement (`position` + `reference_id`) is optional as a pair. When omitted,
 * the menu keeps its current order (update) or is appended (create). HTML forms
 * post empty selects as "" — normalize those away before validation runs.
 */
trait NormalizesMenuPlacementFields
{
    protected function normalizePlacementFields(): void
    {
        if ($this->input('parent') === '') {
            $this->merge(['parent' => null]);
        }

        if ($this->input('position') === '') {
            $this->request->remove('position');
        }

        $position = $this->input('position');

        if (! in_array($position, ['before', 'after'], true)) {
            $this->request->remove('reference_id');

            return;
        }

        if ($this->input('reference_id') === '') {
            $this->request->remove('reference_id');
        }
    }
}
