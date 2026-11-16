<?php

namespace App\Domain\Navigation\Models;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Translatable\HasTranslations;

class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory, HasTranslations, HasUuids, LogsActivity, SoftDeletes;

    /**
     * @return MenuFactory
     */
    protected static function newFactory(): MenuFactory
    {
        return MenuFactory::new();
    }

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    protected $table = 'menus';

    protected $fillable = [
        'parent_id',
        'layout',
        'name',
        'description',
        'icon',
        'route',
        'hash',
        'type',
        'order',
        'audience',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => MenuAudience::class,
            'order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'menu_roles');
    }

    public function includedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'menu_user_includes');
    }

    public function excludedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'menu_user_excludes');
    }

    public function scopeHeaders(Builder $query): Builder
    {
        return $query->where('type', 'h')->whereNull('parent_id');
    }

    public function scopeParents(Builder $query): Builder
    {
        return $query->where('type', 'p');
    }

    public function scopeChildren(Builder $query): Builder
    {
        return $query->where('type', 'c');
    }

    public function scopeByParent(Builder $query, ?string $parentId): Builder
    {
        return $parentId === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parentId);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeByLayout(Builder $query, string $layout): Builder
    {
        return $query->where('layout', $layout);
    }

    /**
     * Shape used when building the runtime navigation tree.
     *
     * @return array<string, mixed>
     */
    public function toNavigationArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'route' => $this->route,
            'hash' => $this->hash,
            'type' => $this->type,
            'order' => $this->order,
            'children' => [],
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'parent_id',
                'layout',
                'name',
                'description',
                'icon',
                'route',
                'hash',
                'type',
                'order',
                'audience',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('menu');
    }
}
