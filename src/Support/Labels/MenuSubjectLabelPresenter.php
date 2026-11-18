<?php

namespace App\Domain\Navigation\Support\Labels;

use App\Domain\Navigation\Models\Menu;
use App\Support\ActivityLog\SubjectLabelPresenter;
use Illuminate\Database\Eloquent\Model;

/**
 * A menu's label is its translated `name`, not an ID — registered with the audit
 * package's kernel seam so the audit package never imports this one.
 */
final class MenuSubjectLabelPresenter implements SubjectLabelPresenter
{
    public function label(Model $subject): string
    {
        if (! $subject instanceof Menu) {
            return (string) ($subject->getKey() ?? '');
        }

        if (is_array($subject->name)) {
            return $subject->getTranslation('name', app()->getLocale()) ?: (string) reset($subject->name);
        }

        return (string) $subject->name;
    }
}
