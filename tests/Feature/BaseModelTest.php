<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The spine every table shares: time-ordered keys, and a record of who did what.
 */
class BaseModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_keys_are_version_7_uuids(): void
    {
        $branch = Branch::factory()->create();

        // 8-4-4-4-12, with the version nibble at position 14.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $branch->id,
            'Keys must be UUID version 7, not random v4.'
        );
    }

    public function test_keys_sort_in_creation_order(): void
    {
        // This is the whole reason for v7. In InnoDB the primary key is the
        // physical row order, so keys that sort by time keep inserts appending
        // instead of scattering across pages.
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = Branch::factory()->create()->id;
            usleep(2000);
        }

        $sorted = $ids;
        sort($sorted);

        $this->assertSame($ids, $sorted, 'Keys created in sequence must also sort in sequence.');
    }

    public function test_the_acting_user_is_recorded_on_create_and_update(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);

        $branch = Branch::factory()->create();

        $this->assertSame($actor->id, $branch->created_by);
        $this->assertSame($actor->id, $branch->updated_by);

        $other = User::factory()->superAdmin()->create();
        $this->actingAs($other);

        $branch->update(['name' => 'Renamed']);

        $this->assertSame($actor->id, $branch->fresh()->created_by, 'created_by must not change on update.');
        $this->assertSame($other->id, $branch->fresh()->updated_by);
    }

    public function test_work_with_no_logged_in_user_is_attributed_to_the_system(): void
    {
        // A scheduled job has no user. Null here means "the system did it",
        // which is a deliberate choice rather than an oversight.
        $branch = Branch::factory()->create();

        $this->assertNull($branch->created_by);
        $this->assertNull($branch->updated_by);
    }

    public function test_deleting_records_who_deleted_and_keeps_the_row(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);

        $branch = Branch::factory()->create();
        $branch->delete();

        $this->assertSoftDeleted('branches', ['id' => $branch->id]);

        $trashed = Branch::withTrashed()->find($branch->id);

        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame($actor->id, $trashed->deleted_by);
    }

    public function test_an_email_can_be_reused_after_the_user_is_deleted(): void
    {
        // The unique key includes deleted_at, so a soft deleted account does
        // not permanently reserve the address.
        $user = User::factory()->create(['email' => 'reuse@eswachh.test']);
        $user->delete();

        $replacement = User::factory()->create(['email' => 'reuse@eswachh.test']);

        $this->assertNotSame($user->id, $replacement->id);
        $this->assertSame('reuse@eswachh.test', $replacement->email);
    }
}
