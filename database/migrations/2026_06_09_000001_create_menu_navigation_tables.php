<?php

use App\Domain\Navigation\Enums\MenuAudience;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu navigation, squashed.
 *
 * The `is_authenticate` / `is_guest_only` boolean pair is gone: `audience`
 * carries the axis explicitly (role restrictions live inside it as `role`).
 * Translatable `name` / `description` are JSON from the start, and `layout`
 * partitions the ordering key so the sidebar and any future layout order
 * independently.
 */
return new class extends Migration
{
    /**
     * The Spatie roles table name.
     */
    private function rolesTable(): string
    {
        return config('permission.table_names.roles', 'roles');
    }

    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('layout', 32)->default('sidebar');
            $table->string('icon')->nullable();
            $table->string('route')->nullable();
            // Content hash used by the seeder to detect a changed menu row.
            $table->string('hash', 64)->nullable();
            // Localized {locale: string} maps, resolved by the API resource.
            $table->json('name')->nullable();
            $table->json('description')->nullable();
            // h = header, p = parent, c = child.
            $table->char('type', 1);
            // Position within a (layout, parent_id, type) sibling group. Nullable
            // on purpose: an active row always holds a distinct integer, while
            // soft-deleted (and transiently reordered) rows are parked at NULL so
            // they never collide with the unique index below. Multiple NULLs are
            // allowed by SQL unique semantics on every supported engine.
            $table->unsignedInteger('order')->nullable();
            $table->string('audience', 16)->default(MenuAudience::Auth->value);
            $table->softDeletes();
            $table->timestamps();

            // Enforce the ordering invariant at the database level for active rows.
            $table->index('parent_id', 'menus_parent_id_index');
            $table->unique(['layout', 'parent_id', 'type', 'order']);
        });

        Schema::table('menus', function (Blueprint $table): void {
            $table->foreign('parent_id', 'menus_parent_id_foreign')
                ->references('id')
                ->on('menus')
                ->cascadeOnDelete();
        });

        Schema::create('menu_roles', function (Blueprint $table) {
            $table->foreignUuid('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained($this->rolesTable())->cascadeOnDelete();

            $table->primary(['menu_id', 'role_id']);
        });

        Schema::create('menu_user_includes', function (Blueprint $table) {
            $table->foreignUuid('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['menu_id', 'user_id']);
        });

        Schema::create('menu_user_excludes', function (Blueprint $table) {
            $table->foreignUuid('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['menu_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_user_excludes');
        Schema::dropIfExists('menu_user_includes');
        Schema::dropIfExists('menu_roles');
        Schema::dropIfExists('menus');
    }
};
