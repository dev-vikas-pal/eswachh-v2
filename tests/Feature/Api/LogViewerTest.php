<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\User;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Reading the log from a screen.
 *
 * The tests that matter are about what it will not open. A log viewer that
 * takes a filename from a request is the classic way to hand somebody the .env
 * file, so the date is matched against a pattern and the path is rebuilt here.
 */
class LogViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<int, string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->admin = User::factory()->superAdmin()->create();
    }

    protected function tearDown(): void
    {
        // Only the files this test made. The developer's real log stays.
        foreach ($this->written as $path) {
            File::delete($path);
        }

        SectorContext::reset();
        parent::tearDown();
    }

    public function test_it_lists_the_days_that_have_a_log(): void
    {
        $this->writeLog('2030-01-02', "[2030-01-02 09:00:00] local.INFO: Something happened\n");

        $dates = collect($this->actingAs($this->admin)->getJson('/api/v1/logs')->assertOk()->json('data'))
            ->pluck('date');

        $this->assertTrue($dates->contains('2030-01-02'));
    }

    public function test_it_reads_one_day(): void
    {
        $this->writeLog('2030-01-03', implode('', [
            "[2030-01-03 09:00:00] local.INFO: A quiet note\n",
            "[2030-01-03 09:05:00] local.ERROR: Something broke\n",
        ]));

        $this->actingAs($this->admin)
            ->getJson('/api/v1/logs/2030-01-03')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            // Newest first: the thing that just went wrong is what somebody
            // opened the screen for.
            ->assertJsonPath('data.0.level', 'ERROR')
            ->assertJsonPath('data.1.level', 'INFO');
    }

    public function test_a_stack_trace_stays_with_its_entry(): void
    {
        $this->writeLog('2030-01-04', implode('', [
            "[2030-01-04 09:00:00] local.ERROR: It broke\n",
            "#0 /app/one.php(12): boom()\n",
            "#1 /app/two.php(34): bang()\n",
            "[2030-01-04 09:01:00] local.INFO: Carried on\n",
        ]));

        $body = $this->actingAs($this->admin)->getJson('/api/v1/logs/2030-01-04')->assertOk()->json();

        // Two entries, not four. Splitting on newlines would turn one exception
        // into a screenful of useless rows.
        $this->assertSame(2, $body['meta']['total']);
        $this->assertStringContainsString('#0 /app/one.php', $body['data'][1]['context']);
    }

    public function test_it_can_be_narrowed_by_level_and_by_words(): void
    {
        $this->writeLog('2030-01-05', implode('', [
            "[2030-01-05 09:00:00] local.INFO: Payment received from Asha\n",
            "[2030-01-05 09:01:00] local.ERROR: Gateway refused\n",
            "[2030-01-05 09:02:00] local.INFO: Reminder sent\n",
        ]));

        $this->actingAs($this->admin)
            ->getJson('/api/v1/logs/2030-01-05?level=error')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/logs/2030-01-05?search=asha')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.level', 'INFO');
    }

    public function test_it_refuses_anything_that_is_not_a_date(): void
    {
        // The whole class of attack, rather than one example of it: nothing
        // from the request is ever used as a path.
        foreach (['../.env', '../../.env', 'laravel', '2030-1-5', '2030-01-05.log'] as $attempt) {
            $this->actingAs($this->admin)
                ->getJson('/api/v1/logs/'.urlencode($attempt))
                ->assertNotFound();
        }
    }

    public function test_a_franchise_owner_cannot_read_the_log(): void
    {
        // Logs carry phone numbers, payment references and whole request
        // bodies. This is not a franchise owner's screen.
        $owner = User::factory()->franchiseOwner(Branch::factory()->create())->create();

        $this->actingAs($owner)->getJson('/api/v1/logs')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/logs/2030-01-02')->assertForbidden();
    }

    public function test_the_daily_channel_is_what_actually_runs(): void
    {
        /*
         * The screen can only show what the application writes, and it reads
         * laravel-*.log. If the daily channel goes back to a single
         * ever-growing file, or stops pruning, this fails.
         *
         * The stack is deliberately not asserted: under tests it is redirected
         * to a file of its own, because the suite provokes failures on purpose
         * and two hundred of them were landing in the log this screen reads.
         */
        $this->assertSame('daily', config('logging.channels.daily.driver'));
        $this->assertStringEndsWith('laravel.log', config('logging.channels.daily.path'));
        // Loosely compared: it arrives as a string from the environment.
        $this->assertEquals(10, config('logging.channels.daily.days'));
    }

    public function test_the_suite_does_not_write_to_the_log_this_screen_reads(): void
    {
        // The guard for the thing that actually went wrong: a test run left two
        // hundred alarming entries against nine real ones, and every one of
        // them was a test doing its job.
        $this->assertSame('testing', config('logging.default'));
        $this->assertStringEndsWith('testing.log', config('logging.channels.testing.path'));
    }

    private function writeLog(string $date, string $contents): void
    {
        $path = storage_path('logs/laravel-'.$date.'.log');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        $this->written[] = $path;
    }
}
