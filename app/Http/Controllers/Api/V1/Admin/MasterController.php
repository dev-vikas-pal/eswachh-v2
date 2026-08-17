<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Content\HtmlSanitizer;
use App\Support\Content\RichText;
use App\Support\Masters\MasterRegistry;
use App\Support\Tenancy\SectorContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        return SectorContext::withoutScope(function () use ($definition, $filters) {
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

        return SectorContext::withoutScope(function () use ($definition, $data) {
            $row = $definition['model']::create($data);

            return response()->json(['data' => $this->present($row, $definition)], 201);
        });
    }

    public function update(Request $request, string $master, string $id): JsonResponse
    {
        $definition = $this->definition($master);
        $data = $this->validated($request, $definition, $id);

        return SectorContext::withoutScope(function () use ($definition, $id, $data) {
            $row = $definition['model']::findOrFail($id);
            $row->update($data);

            /*
             * Who covers a sector is not edited here.
             *
             * It is assigned on the person, under People, because that is the
             * moment it matters: an account created without a sector signs in
             * to empty screens. This screen only reports it - see present().
             */
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

        return SectorContext::withoutScope(function () use ($definition, $id) {
            $row = $definition['model']::findOrFail($id);

            /*
             * A sector is not a price list row and cannot be treated like one.
             *
             * Withdrawing a package leaves the plans on it running. Withdrawing
             * a sector that still has customers in it makes every one of them
             * invisible to the staff assigned there, because visibility is
             * worked out from exactly this row - and nobody would connect the
             * empty screen back to this button.
             */
            if ($definition['key'] === 'sectors' && ($living = $this->customersLivingIn($id)) > 0) {
                throw ValidationException::withMessages([
                    'id' => "This sector still has {$living} customer(s) in it. Move them to another sector "
                        .'first, or switch this one off instead of withdrawing it.',
                ]);
            }

            $inUse = $this->countLivePlansUsing($definition['key'], $id);

            $row->delete();

            // "Withdrawn from sale" is the right words for a package and the
            // wrong ones for a sector or a society.
            $done = ($definition['group'] ?? null) === 'Price list'
                ? 'Withdrawn from sale.'
                : 'Withdrawn.';

            return response()->json([
                'message' => $inUse > 0
                    // Said plainly rather than blocked: withdrawing something
                    // that is still selling is a normal thing to want to do,
                    // but whoever does it should know what it affects.
                    ? "{$done} {$inUse} running plan(s) still refer to it and are unaffected."
                    : $done,
                'in_use' => $inUse,
            ]);
        });
    }

    public function restore(string $master, string $id): JsonResponse
    {
        $definition = $this->definition($master);

        return SectorContext::withoutScope(function () use ($definition, $id) {
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
     * How many customers would lose their territory if this sector went.
     *
     * Counted including withdrawn rows: a deleted customer restored later still
     * needs somewhere to belong.
     */
    private function customersLivingIn(string $sectorId): int
    {
        return SectorContext::withoutScope(
            fn () => Customer::withTrashed()->where('sector_id', $sectorId)->count()
        );
    }

    private function validated(Request $request, array $definition, ?string $id = null): array
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

        /*
         * Fields that may not repeat - a franchise code today.
         *
         * Checked here rather than left to the unique index, because the index
         * throws a driver exception that reaches the browser as "Something went
         * wrong" with the real reason only in the log. Withdrawn rows are
         * excluded, so a code freed by closing a franchise can be used again.
         */
        $table = (new $definition['model'])->getTable();

        foreach ($definition['unique'] ?? [] as $field) {
            $rules[$field] = array_merge(
                $rules[$field] ?? ['nullable'],
                [Rule::unique($table, $field)->ignore($id)->whereNull('deleted_at')],
            );
        }

        $data = $request->validate($rules);

        /*
         * Which franchise services a sector is an administrator's decision.
         *
         * It is the one master field with an operational consequence: it
         * decides whose round the work lands on and which branch owns every
         * customer in that sector. A franchise owner able to set it could
         * quietly move another franchise's sector - and their customers - onto
         * their own books. Dropped rather than refused, so the rest of an
         * otherwise legitimate edit still saves.
         */
        if (array_key_exists('branch_id', $data)
            && $request->user()?->role !== UserRole::SuperAdmin) {
            unset($data['branch_id']);
        }

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
         * Who covers this sector, on the row itself.
         *
         * Sent with the list rather than fetched when the form opens, because
         * the answer belongs in the table too: "which sectors has nobody got"
         * is the question this screen exists to answer, and it should be
         * readable without opening anything.
         */
        if ($definition['staff'] ?? false) {
            $staff = $row->staff()->orderBy('name')->get(['users.id', 'users.name']);

            $out['staff_ids'] = $staff->pluck('id')->all();
            $out['staff_names'] = $staff->pluck('name')->implode(', ');
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
