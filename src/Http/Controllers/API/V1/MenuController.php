<?php

namespace App\Domain\Navigation\Http\Controllers\API\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Navigation\Http\Requests\IndexMenuListRequest;
use App\Domain\Navigation\Http\Requests\IndexNavigationMenuRequest;
use App\Domain\Navigation\Http\Requests\StoreMenuRequest;
use App\Domain\Navigation\Http\Requests\UpdateMenuRequest;
use App\Domain\Navigation\Http\Resources\MenuDetailResource;
use App\Domain\Navigation\Http\Resources\MenuListResource;
use App\Domain\Navigation\Http\Resources\MenuTrashedResource;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Services\MenuService;
use App\Domain\Navigation\Support\MenuLayout;
use App\Domain\Navigation\Support\MenuPresenter;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ResolvesResourceArray;
use App\Domain\Navigation\Support\ListActions\MenuQueryRowActions;
use App\Domain\Navigation\Support\ListActions\MenuTrashedQueryRowActions;
use App\Support\ListActions\QueryRowActionAttacher;
use App\Traits\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MenuController extends Controller
{
    use ApiResponse;
    use ResolvesResourceArray;

    public function __construct(
        private readonly MenuService $menus,
    ) {}

    /**
     * Auth-aware navigation tree for the current request (guest or authenticated).
     */
    public function index(IndexNavigationMenuRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $tree = $this->menus->navigationTree(
            $this->optionalActor(),
            $validated['q'] ?? null,
            $validated['layout'] ?? MenuLayout::default(),
        );

        return $this->successResponse($tree, __('api/navigation.tree_success'));
    }

    /**
     * Expose the configured menu layout identifiers so SPA admin screens can
     * render layout select options dynamically without hardcoding.
     */
    public function layouts(): JsonResponse
    {
        return $this->successResponse(
            ['layouts' => MenuLayout::all()],
            __('api/navigation.layouts_success'),
        );
    }

    /**
     * Hierarchically sorted flat list for management screens.
     */
    public function list(IndexMenuListRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $loadType = $validated['load_type'] ?? null;
        $layout = $validated['layout'] ?? MenuLayout::default();
        $actor = $this->requiredActor();
        $menus = $this->menus->adminList($loadType, $layout);

        return $this->successResponse(
            QueryRowActionAttacher::attach(
                $this->resolveResource(MenuListResource::collection($menus)),
                $menus,
                new MenuQueryRowActions($layout),
                $actor,
            ),
            __('api/navigation.list_success'),
        );
    }

    public function store(StoreMenuRequest $request): JsonResponse
    {
        $menu = $this->menus->create($request->validated());

        return $this->successResponse(
            MenuPresenter::presentDetail($menu),
            __('api/navigation.created'),
            201,
        );
    }

    public function show(Menu $menu): JsonResponse
    {
        return $this->successResponse(
            $this->resolveResource(new MenuDetailResource($this->menus->detail($menu->id))),
            __('api/navigation.show_success'),
        );
    }

    public function update(UpdateMenuRequest $request, Menu $menu): JsonResponse
    {
        $updated = $this->menus->update($menu, $request->validated());

        return $this->successResponse(
            MenuPresenter::presentDetail($updated),
            __('api/navigation.updated'),
        );
    }

    public function destroy(Request $request, Menu $menu): JsonResponse
    {
        $this->menus->delete($menu, $request->boolean('cascade'));

        return $this->successResponse(null, __('api/navigation.deleted'));
    }

    /**
     * Soft-deleted menus for the recovery screen.
     */
    public function trashed(IndexMenuListRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $loadType = $validated['load_type'] ?? null;
        $layout = $validated['layout'] ?? MenuLayout::default();
        $actor = $this->requiredActor();
        $menus = $this->menus->trashedList($loadType, $layout);

        return $this->successResponse(
            QueryRowActionAttacher::attach(
                $this->resolveResource(MenuTrashedResource::collection($menus)),
                $menus,
                new MenuTrashedQueryRowActions,
                $actor,
            ),
            __('api/navigation.trashed_success'),
        );
    }

    /**
     * Restore a soft-deleted menu and its trashed subtree. `{menu}` is bound
     * with `withTrashed()` so the trashed record resolves.
     */
    public function restore(Menu $menu): JsonResponse
    {
        $this->menus->restore($menu);

        return $this->successResponse(
            MenuPresenter::presentDetail($this->menus->detail($menu->id)),
            __('api/navigation.restored'),
        );
    }

    private function optionalActor(): ?User
    {
        $user = Auth::guard('api')->user();

        return $user instanceof User ? $user : null;
    }

    private function requiredActor(): User
    {
        $user = Auth::guard('api')->user();

        if (! $user instanceof User) {
            throw new HttpResponseException(
                $this->errorResponse(__('api/auth.bearer_required'), 401),
            );
        }

        return $user;
    }
}
