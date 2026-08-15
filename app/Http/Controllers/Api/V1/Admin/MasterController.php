<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\Content\HtmlSanitizer;
use App\Support\Content\RichText;
use App\Support\Masters\MasterRegistry;
use App\Support\Tenancy\BranchContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The reference lists: geography and the price list.
 *
 * These are a shared catalogue rather than branch property - every franchise
 * sells from the same list - so they are read and written outside the branch
 * scope, and only a super admin may touch them.
 */
class MasterController extends Controller implements HasMiddleware
{
    /**
     * Editing a price here changes what every franchise charges, so this is
     * deliberately not something a franchise owner can do.
     *
     * Declared once for the whole controller rather than repeated on each
     * route, so a method added later is protected by default instead of by
     * remembering.
     */
    public static function middleware(): array
    {
        return ['can:manage.master'];
    }

    /** What lists exist, for building the menu. */
    public function catalogue(): JsonResponse
    {
        return response()->json(['data' => MasterRegistry::index()]);
    }

    public function index(Request $request, string $master): JsonResponse
    {
        $definition = $this->definition($master);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'parent_id' => ['sometimes', 'string'],
            'include_withdrawn' => ['sometimes', 'boolean'],
        ]);

        return BranchContext::withoutScope(function () use ($definition, $filters) {
            $titleField = $definition['titleField'] ?? 'name';

            $query = $definition['model']::query();

            /*
             * Reference lists read best alphabetically; anything with a
             * sort_order is arranged by hand because the order is the point -
             * a banner shows the first one, and questions are asked in a
             * sequence somebody chose.
             */
            if (in_array('sort_order', array_keys($definition['fields']), true)) {
                $query->orderBy('sort_order')->orderBy('created_at');
            } else {
                $query->orderBy($titleField);
            }

            /*
             * Withdrawn rows are hidden by default but can be asked for. They
             * still exist because live plans point at them - a package removed
             * from sale two years ago is still what somebody is paying for.
             */
            if ($filters['include_withdrawn'] ?? false) {
                $query->withTrashed();
            }

            if ($search = $filters['search'] ?? null) {
                $query->where($titleField, 'like', "%{$search}%");
            }

            if (isset($definition['parent'], $filters['parent_id'])) {
                $query->where($definition['parent']['key'], $filters['parent_id']);
            }

            return response()->json([
                'data' => $query->get()->map(fn (Model $row) => $this->present($row, $definition)),
                'meta' => [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'singular' => $definition['singular'],
                    'parent' => $definition['parent'] ?? null,
                    'money' => $definition['money'] ?? [],
                ],
            ]);
        });
    }

    public function store(Request $request, string $master): JsonResponse
    {
        $definition = $this->definition($master);
        $data = $this->validated($request, $definition);

        return BranchContext::withoutScope(function () use ($definition, $data) {
            $row = $definition['model']::create($data);

            return response()->json(['data' => $this->present($row, $definition)], 201);
        });
    }

    public function update(Request $request, string $master, string $id): JsonResponse
    {
        $definition = $this->definition($master);
        $data = $this->validated($request, $definition);

        return BranchContext::withoutScope(function () use ($definition, $id, $data) {
            $row = $definition['model']::findOrFail($id);
            $row->update($data);

            return response()->json(['data' => $this->present($row->fresh(), $definition)]);
        });
    }

    /**
     * Withdraw a master from sale.
     *
     * A soft delete, always. Rows here are referenced by live subscriptions and
     * by historic ones, so removing one for real would change what a customer
     * is paying for and silently re-price their renewal.
     */
    public function destroy(string $master, string $id): JsonResponse
    {
        $definition = $this->definition($master);

        return BranchContext::withoutScope(function () use ($definition, $id) {
            $row = $definition['model']::findOrFail($id);

            $inUse = $this->countLivePlansUsing($definition['key'], $id);

            $row->delete();

            return response()->json([
                'message' => $inUse > 0
                    // Said plainly rather than blocked: withdrawing something
                    // that is still selling is a normal thing to want to do,
                    // but whoever does it should know what it affects.
                    ? "Withdrawn from sale. {$inUse} running plan(s) still refer to it and are unaffected."
                    : 'Withdrawn from sale.',
                'in_use' => $inUse,
            ]);
        });
    }

    public function restore(string $master, string $id): JsonResponse
    {
        $definition = $this->definition($master);

        return BranchContext::withoutScope(function () use ($definition, $id) {
            $row = $definition['model']::withTrashed()->findOrFail($id);
            $row->restore();

            return response()->json(['data' => $this->present($row, $definition)]);
        });
    }

    // ---------------------------------------------------------------- private

    /**
     * @return array<string, mixed>
     */
    private function definition(string $master): array
    {
        abort_unless(MasterRegistry::exists($master), 404);

        return MasterRegistry::get($master);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $definition): array
    {
        $rules = $definition['fields'];
        $rules['status'] = ['sometimes', 'boolean'];

        if (isset($definition['parent'])) {
            $parentTable = MasterRegistry::get($definition['parent']['master'])['model'];
            $rules[$definition['parent']['key']] = [
                'required', 'string',
                'exists:'.(new $parentTable)->getTable().',id',
            ];
        }

        $data = $request->validate($rules);

        if (isset($data['months']) && $data['months'] < 1) {
            throw ValidationException::withMessages([
                'months' => 'A duration has to be at least one month.',
            ]);
        }

        /*
         * Formatted fields are reduced to a short whitelist before they are
         * stored, never on the way out. Cleaning at render time would leave the
         * mess in the database for every future reader to remember to handle -
         * and one that forgets is a hole straight onto the public site.
         */
        foreach ($definition['rich'] ?? [] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = HtmlSanitizer::clean($data[$field]);
            }
        }

        return $data;
    }

    /**
     * How many running plans depend on this row.
     *
     * Only the masters a subscription actually points at can be counted; the
     * rest return zero rather than a wrong number.
     */
    private function countLivePlansUsing(string $master, string $id): int
    {
        $column = match ($master) {
            'packages' => 'package_id',
            'service-types' => 'service_type_id',
            'durations' => 'duration_id',
            'cloth-bundles' => 'cloth_bundle_id',
            default => null,
        };

        if ($column === null) {
            return 0;
        }

        return Subscription::query()->active()->where($column, $id)->count();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function present(Model $row, array $definition): array
    {
        $titleField = $definition['titleField'] ?? 'name';

        $out = [
            'id' => $row->getKey(),
            // Whatever this master calls its label - name, headline, question -
            // so the table has one column to show without knowing which master
            // it is looking at.
            'name' => $row->{$titleField},
            'status' => (bool) $row->status,
            // Withdrawn rather than deleted, because that is what it means here.
            'withdrawn' => $row->deleted_at !== null,
        ];

        foreach (array_keys($definition['fields']) as $field) {
            $out[$field] = $row->{$field};
        }

        /*
         * A formatted field goes to the table as readable text and to the form
         * as markup. Sending only the markup is what put a screenful of
         * inline styles in the packages list.
         */
        foreach ($definition['rich'] ?? [] as $field) {
            $out[$field.'_text'] = RichText::summary($row->{$field}, 140);
        }

        // Money is sent in both forms so the screen never divides by 100 and
        // never rounds a rupee figure twice.
        foreach ($definition['money'] ?? [] as $field) {
            $out[str_replace('_paise', '', $field)] = ((int) $row->{$field}) / 100;
        }

        if (isset($definition['parent'])) {
            $out['parent_id'] = $row->{$definition['parent']['key']};
        }

        return $out;
    }
}
