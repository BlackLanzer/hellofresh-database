<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DownloadImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function it_downloads_recipe_images(): void
    {
        Http::fake(['*' => Http::response('image', 200)]);
        Recipe::factory()->create(['image_path' => '/recipe-a.jpg']);

        $this->artisan('images:download', ['--type' => 'recipes'])->assertSuccessful();

        Storage::disk('public')->assertExists('hellofresh/recipe-a.jpg');
    }

    #[Test]
    public function it_only_downloads_published_recipes_with_the_published_option(): void
    {
        Http::fake(['*' => Http::response('image', 200)]);
        Recipe::factory()->create(['image_path' => '/published.jpg', 'published' => true]);
        Recipe::factory()->create(['image_path' => '/archived.jpg', 'published' => false]);

        $this->artisan('images:download', ['--type' => 'recipes', '--published' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('hellofresh/published.jpg');
        Storage::disk('public')->assertMissing('hellofresh/archived.jpg');
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_downloads_archived_recipes_without_the_published_option(): void
    {
        Http::fake(['*' => Http::response('image', 200)]);
        Recipe::factory()->create(['image_path' => '/archived.jpg', 'published' => false]);

        $this->artisan('images:download', ['--type' => 'recipes'])->assertSuccessful();

        Storage::disk('public')->assertExists('hellofresh/archived.jpg');
    }

    #[Test]
    public function it_stops_after_the_limit_of_new_downloads(): void
    {
        Http::fake(['*' => Http::response('image', 200)]);
        Recipe::factory()->create(['image_path' => '/recipe-a.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-b.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-c.jpg']);

        $this->artisan('images:download', ['--type' => 'recipes', '--limit' => 2])
            ->expectsOutputToContain('Limit of 2 new images reached')
            ->assertSuccessful();

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_does_not_count_existing_images_towards_the_limit(): void
    {
        Http::fake(['*' => Http::response('image', 200)]);
        Storage::disk('public')->put('hellofresh/recipe-a.jpg', 'existing');
        Recipe::factory()->create(['image_path' => '/recipe-a.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-b.jpg']);

        $this->artisan('images:download', ['--type' => 'recipes', '--limit' => 1])->assertSuccessful();

        Storage::disk('public')->assertExists('hellofresh/recipe-b.jpg');
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_aborts_after_consecutive_blocked_requests(): void
    {
        Http::fake(['*' => Http::response('', 429)]);
        Recipe::factory()->create(['image_path' => '/recipe-a.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-b.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-c.jpg']);

        $this->artisan('images:download', ['--type' => 'recipes', '--max-failures' => 2])
            ->expectsOutputToContain('Aborted after 2 consecutive blocked or failed requests')
            ->assertFailed();

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_does_not_abort_on_missing_images(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        Recipe::factory()->create(['image_path' => '/recipe-a.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-b.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-c.jpg']);

        $this->artisan('images:download', ['--type' => 'recipes', '--max-failures' => 2])->assertSuccessful();

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_never_aborts_when_max_failures_is_zero(): void
    {
        Http::fake(['*' => Http::response('', 429)]);
        Recipe::factory()->create(['image_path' => '/recipe-a.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-b.jpg']);
        Recipe::factory()->create(['image_path' => '/recipe-c.jpg']);

        $this->artisan('images:download', ['--type' => 'recipes', '--max-failures' => 0])->assertSuccessful();

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_rejects_invalid_throttling_options(): void
    {
        Http::fake();
        Recipe::factory()->create(['image_path' => '/recipe-a.jpg']);

        $this->artisan('images:download', ['--type' => 'recipes', '--limit' => 0])->assertFailed();
        $this->artisan('images:download', ['--type' => 'recipes', '--delay' => -5])->assertFailed();
        $this->artisan('images:download', ['--type' => 'recipes', '--max-failures' => 'abc'])->assertFailed();

        Http::assertNothingSent();
    }
}
