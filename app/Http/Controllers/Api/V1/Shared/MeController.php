<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Support\Tenancy\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the SPA needs to draw itself, in one call.
 *
 * Who you are, what you may do, and which branches you can look at. The front
 * end renders its navigation and branch selector from this rather than making
 * a call per question.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load('branch');

        return response()->json([
            'data' => new UserResource($user),
            'branches' => $this->branchesFor($user),
        ]);
    }

    /**
     * Branches this user may look at.
     *
     * A super admin gets all of them; everyone else gets their own. The list is
     * for rendering the selector - the server still validates the branch on
     * every request, so a doctored list buys nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function branchesFor($user): array
    {
        $query = BranchContext::withoutScope(
            fn () => Branch::query()->active()->orderBy('name')
        );

        if (! $user->seesAllBranches()) {
            $query->where('id', $user->branch_id);
        }

        return $query->get(['id', 'name'])->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'name' => $branch->name,
        ])->all();
    }
}
