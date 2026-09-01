<?php

namespace Tests\Feature\Site;

use App\Models\Faq;
use App\Support\Settings\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a search engine and a chat app actually receive.
 *
 * All of this is rendered by the server, before any JavaScript runs, and none
 * of it is visible in a browser - so a change that quietly drops the structured
 * data or the per-page title would go unnoticed until somebody thought to view
 * the source. These tests are the only thing watching.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_page_has_a_title_of_its_own(): void
    {
        /*
         * Every route shared one title, so eight pages competed with each other
         * in search results under the same name and a bookmarked price list was
         * indistinguishable from the home page.
         */
        $this->get('/')->assertOk()->assertSee('<title>', false);

        $this->get('/packages')->assertOk()->assertSee('<title>Plans and prices ·', false);
        $this->get('/subscribe')->assertOk()->assertSee('<title>Start a subscription ·', false);
        $this->get('/questions')->assertOk()->assertSee('<title>Common questions ·', false);
    }

    public function test_the_business_is_described_as_structured_data(): void
    {
        SiteSettings::put(['contact_phone' => '9876543210', 'business_name' => 'Eswachh']);

        $schema = $this->schemaFrom($this->get('/')->getContent(), 'LocalBusiness');

        $this->assertNotNull($schema, 'No LocalBusiness schema on the home page.');
        $this->assertSame('Eswachh', $schema['name']);
        $this->assertSame('9876543210', $schema['telephone']);
    }

    public function test_an_unfilled_contact_detail_is_left_out_rather_than_sent_empty(): void
    {
        SiteSettings::put(['contact_phone' => '', 'address' => '']);

        $schema = $this->schemaFrom($this->get('/')->getContent(), 'LocalBusiness');

        /*
         * An empty telephone in structured data is worse than none: it is a
         * claim that there is nothing to say, rather than a field nobody has
         * filled in yet.
         */
        $this->assertArrayNotHasKey('telephone', $schema);
        $this->assertArrayNotHasKey('address', $schema);
    }

    public function test_the_offices_own_questions_are_offered_as_rich_results(): void
    {
        Faq::create([
            'question' => 'What time does the cleaner come?',
            'answer' => '<p>Before <strong>8am</strong>, every day.</p>',
            'status' => true,
        ]);

        $schema = $this->schemaFrom($this->get('/')->getContent(), 'FAQPage');

        $this->assertNotNull($schema, 'No FAQ schema, so no rich result.');
        $this->assertSame('What time does the cleaner come?', $schema['mainEntity'][0]['name']);

        // Tags stripped: a rich result renders text, not markup.
        $this->assertSame('Before 8am, every day.', $schema['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function test_a_copy_that_is_not_meant_to_be_found_asks_not_to_be(): void
    {
        SiteSettings::put(['seo_index' => '0']);

        $html = $this->get('/')->getContent();

        // A staging copy that gets indexed competes with the real site for its
        // own name, and its questions must not be offered up either.
        $this->assertStringContainsString('noindex', $html);
        $this->assertNull($this->schemaFrom($html, 'FAQPage'));
    }

    /**
     * One block of structured data out of the page, by its type.
     *
     * @return array<string, mixed>|null
     */
    private function schemaFrom(string $html, string $type): ?array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);

            // Invalid JSON in a script tag is worse than no script tag: it is
            // ignored silently by every consumer.
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Structured data is not valid JSON.');

            if (($decoded['@type'] ?? null) === $type) {
                return $decoded;
            }
        }

        return null;
    }
}
