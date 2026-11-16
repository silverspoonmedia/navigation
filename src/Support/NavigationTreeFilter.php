<?php

namespace App\Domain\Navigation\Support;

/**
 * Filters a per-viewer navigation tree while keeping ancestor headers/parents
 * visible as context markers for matching descendants.
 */
final class NavigationTreeFilter
{
    /**
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    public function filter(array $tree, string $query): array
    {
        $needle = $this->normalize($query);

        if ($needle === '') {
            return $tree;
        }

        $filtered = [];

        foreach ($tree as $node) {
            $match = $this->filterNode($node, $needle);

            if ($match !== null) {
                $filtered[] = $match;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function filterNode(array $node, string $needle): ?array
    {
        $filteredChildren = [];

        foreach ($node['children'] ?? [] as $child) {
            $match = $this->filterNode($child, $needle);

            if ($match !== null) {
                $filteredChildren[] = $match;
            }
        }

        if (! $this->matches($node, $needle) && $filteredChildren === []) {
            return null;
        }

        $node['children'] = $filteredChildren;

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function matches(array $node, string $needle): bool
    {
        foreach (['name', 'description', 'route'] as $field) {
            $value = $node[$field] ?? '';

            if (! is_string($value) || $value === '') {
                continue;
            }

            if (str_contains($this->normalize($value), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
