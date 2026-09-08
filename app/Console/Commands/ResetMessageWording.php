<?php

namespace App\Console\Commands;

use App\Models\MessageTemplate;
use App\Support\Tenancy\SectorContext;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Console\Command;

/**
 * Puts message wording back to what this release ships with.
 *
 * The seeder deliberately never overwrites an existing template, because
 * re-seeding must not throw away wording the office has changed. That is right,
 * and it leaves a gap: there is no way back. Anybody who edits a template to
 * try something - matching a provider template that is already approved, say,
 * while a corrected one waits out its twenty-four hours - then has to retype the
 * original from memory, and "close enough" is not close enough here. A body
 * with a different number of placeholders than the template MSG91 approved is
 * rejected on every send.
 *
 * So this is the deliberate, named way to overwrite. It says exactly what it
 * changed, and it asks first unless told not to.
 */
class ResetMessageWording extends Command
{
    protected $signature = 'eswachh:reset-message-wording
                            {keys?* : Template keys to reset. All of them if none are named.}
                            {--force : Do not ask}';

    protected $description = 'Put message wording back to what this release ships with';

    public function handle(): int
    {
        /*
         * Unscoped, like everything else that touches the masters: message
         * wording is a shared catalogue, not any sector's property, and this is
         * usually run from a console with nobody signed in.
         */
        return SectorContext::withoutScope(function () {
            $wanted = $this->argument('keys');
            $shipped = collect(MessageTemplateSeeder::templates())
                ->when($wanted, fn ($all) => $all->whereIn('key', $wanted))
                ->keyBy('key');

            if ($shipped->isEmpty()) {
                $this->components->error('No template matches: '.implode(', ', $wanted));

                return self::FAILURE;
            }

            if ($unknown = array_diff($wanted, $shipped->keys()->all())) {
                $this->components->warn('No shipped wording for: '.implode(', ', $unknown));
            }

            $changes = $this->pending($shipped);

            if ($changes->isEmpty()) {
                $this->components->info('Nothing to change: every template already has its shipped wording.');

                return self::SUCCESS;
            }

            $this->report($changes);

            if (! $this->option('force') && ! $this->confirm('Overwrite the wording above?', true)) {
                $this->components->warn('Nothing changed.');

                return self::SUCCESS;
            }

            foreach ($changes as $change) {
                MessageTemplate::query()
                    ->where('key', $change['key'])
                    ->update([
                        'body' => $change['shipped']['body'],
                        'provider_template' => $change['shipped']['provider_template'],
                    ]);
            }

            $this->components->info($changes->count().' template(s) reset.');
            $this->line('  Export them again before uploading to MSG91 - the files carry this wording.');

            return self::SUCCESS;
        });
    }

    /**
     * What actually differs, so a run that changes nothing says so.
     */
    private function pending(\Illuminate\Support\Collection $shipped): \Illuminate\Support\Collection
    {
        return MessageTemplate::query()
            ->whereIn('key', $shipped->keys())
            ->orderBy('key')
            ->get()
            ->map(fn (MessageTemplate $t) => [
                'key' => $t->key,
                'current' => $t,
                'shipped' => $shipped[$t->key],
            ])
            ->filter(fn (array $c) => $c['current']->body !== $c['shipped']['body']
                || $c['current']->provider_template !== $c['shipped']['provider_template'])
            ->values();
    }

    /**
     * Placeholder counts as well as names, because that is the half that breaks
     * delivery: a body with four where the approved template has three is
     * refused by the provider on every send.
     */
    private function report(\Illuminate\Support\Collection $changes): void
    {
        $this->line('');

        foreach ($changes as $change) {
            $this->line('<options=bold>'.$change['key'].'</>');
            $this->line('  now     '.$this->shape($change['current']->body));
            $this->line('  shipped '.$this->shape($change['shipped']['body']));
            $this->line('');
        }
    }

    private function shape(string $body): string
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $body, $matches);
        $names = array_values(array_unique($matches[1]));

        return count($names).' placeholder(s): '.(implode(', ', $names) ?: 'none');
    }
}
