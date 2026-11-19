<?php

namespace App\Domain\Navigation\Http\Controllers\API\V1;

use App\Domain\Navigation\Http\Requests\LookupMenusRequest;
use App\Domain\Navigation\Services\MenuLookupService;
use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Select2 menu lookup for the Dynamic Menu CRUD builder.
 *
 * Served under `/api/v1/navigation/lookups/menus`: it reads `Menu`, which this
 * package owns, so it stays here rather than in the kernel's Identity lookups.
 */
class MenuLookupController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly MenuLookupService $lookups,
    ) {}

    public function menus(LookupMenusRequest $request): JsonResponse
    {
        Gate::authorize('view menu');

        $payload = $this->lookups->menus(
            $request->searchTerm(),
            $request->page(),
            $request->perPage(),
            $request->layout(),
            $request->excludeId(),
            $request->ids(),
        );

        return $this->successResponse($payload, __('api/lookups.menus_success'));
    }
}
