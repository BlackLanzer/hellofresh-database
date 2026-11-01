<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DownloadPdfsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function it_downloads_recipe_card_pdfs(): void
    {
        Http::fake(['*' => Http::response('pdf', 200)]);
        $recipe = Recipe::factory()->create([
            'name' => ['en' => 'Creamy Tomato Pasta'],
            'card_link' => ['en' => 'https://example.com/creamy.pdf'],
        ]);

        $this->artisan('pdfs:download')->assertSuccessful();

        Storage::disk('public')->assertExists(
            'hellofresh/recipecards/' . $recipe->id . '-creamy-tomato-pasta.pdf'
        );
    }

    #[Test]
    public function it_uses_the_card_link_url_as_is(): void
    {
        Http::fake(['*' => Http::response('pdf', 200)]);
        Recipe::factory()->create([
            'card_link' => ['en' => 'https://example.com/recipe-card.pdf'],
        ]);

        $this->artisan('pdfs:download')->assertSuccessful();

        Http::assertSent(
            static fn (Request $request): bool => $request->url() === 'https://example.com/recipe-card.pdf'
        );
    }

    #[Test]
    public function it_resolves_the_url_from_a_non_default_locale(): void
    {
        Http::fake(['*' => Http::response('pdf', 200)]);
        Recipe::factory()->create([
            'card_link' => ['it' => 'https://example.com/it.pdf'],
        ]);

        $this->artisan('pdfs:download')->assertSuccessful();

        Http::assertSent(
            static fn (Request $request): bool => $request->url() === 'https://example.com/it.pdf'
        );
    }

    #[Test]
    public function it_skips_pdfs_that_already_exist(): void
    {
        Http::fake();
        $recipe = Recipe::factory()->create([
            'name' => ['en' => 'Existing Pasta'],
            'card_link' => ['en' => 'https://example.com/existing.pdf'],
        ]);

        Storage::disk('public')->put($recipe->pdf_local_path, 'existing');

        $this->artisan('pdfs:download')->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_redownloads_existing_pdfs_with_the_force_option(): void
    {
        Http::fake(['*' => Http::response('fresh pdf', 200)]);
        $recipe = Recipe::factory()->create([
            'name' => ['en' => 'Existing Pasta'],
            'card_link' => ['en' => 'https://example.com/existing.pdf'],
        ]);

        $localPath = $recipe->pdf_local_path;
        Storage::disk('public')->put($localPath, 'old pdf');

        $this->artisan('pdfs:download', ['--force' => true])->assertSuccessful();

        $this->assertSame('fresh pdf', Storage::disk('public')->get($localPath));
    }

    #[Test]
    public function it_only_downloads_published_recipes_with_the_published_option(): void
    {
        Http::fake(['*' => Http::response('pdf', 200)]);
        $published = Recipe::factory()->create([
            'name' => ['en' => 'Published Pasta'],
            'card_link' => ['en' => 'https://example.com/published.pdf'],
            'published' => true,
        ]);
        $archived = Recipe::factory()->create([
            'name' => ['en' => 'Archived Pasta'],
            'card_link' => ['en' => 'https://example.com/archived.pdf'],
            'published' => false,
        ]);

        $this->artisan('pdfs:download', ['--published' => true])->assertSuccessful();

        Storage::disk('public')->assertExists($published->pdf_local_path);
        Storage::disk('public')->assertMissing($archived->pdf_local_path);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_stops_after_the_limit_of_new_downloads(): void
    {
        Http::fake(['*' => Http::response('pdf', 200)]);
        Recipe::factory()->create([
            'name' => ['en' => 'Recipe A'],
            'card_link' => ['en' => 'https://example.com/a.pdf'],
        ]);
        Recipe::factory()->create([
            'name' => ['en' => 'Recipe B'],
            'card_link' => ['en' => 'https://example.com/b.pdf'],
        ]);
        Recipe::factory()->create([
            'name' => ['en' => 'Recipe C'],
            'card_link' => ['en' => 'https://example.com/c.pdf'],
        ]);

        $this->artisan('pdfs:download', ['--limit' => 2])
            ->expectsOutputToContain('Limit of 2 new PDFs reached')
            ->assertSuccessful();

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_aborts_after_consecutive_blocked_requests(): void
    {
        Http::fake(['*' => Http::response('', 429)]);
        Recipe::factory()->create([
            'name' => ['en' => 'Recipe A'],
            'card_link' => ['en' => 'https://example.com/a.pdf'],
        ]);
        Recipe::factory()->create([
            'name' => ['en' => 'Recipe B'],
            'card_link' => ['en' => 'https://example.com/b.pdf'],
        ]);
        Recipe::factory()->create([
            'name' => ['en' => 'Recipe C'],
            'card_link' => ['en' => 'https://example.com/c.pdf'],
        ]);

        $this->artisan('pdfs:download', ['--max-failures' => 2])
            ->expectsOutputToContain('Aborted after 2 consecutive blocked or failed requests')
            ->assertFailed();

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_skips_recipes_without_a_card_link(): void
    {
        Http::fake();
        Recipe::factory()->create(['card_link' => null]);

        $this->artisan('pdfs:download')->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_rejects_invalid_throttling_options(): void
    {
        Http::fake();
        Recipe::factory()->create([
            'card_link' => ['en' => 'https://example.com/recipe-card.pdf'],
        ]);

        $this->artisan('pdfs:download', ['--limit' => 0])->assertFailed();
        $this->artisan('pdfs:download', ['--delay' => -5])->assertFailed();
        $this->artisan('pdfs:download', ['--max-failures' => 'abc'])->assertFailed();

        Http::assertNothingSent();
    }
}
