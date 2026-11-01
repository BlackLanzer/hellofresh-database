<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Recipe;
use App\Services\Pdf\RecipeCardPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecipeCardPdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected RecipeCardPdfGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->generator = RecipeCardPdfGenerator::make();
    }

    #[Test]
    public function it_creates_instances_through_the_make_factory(): void
    {
        $this->assertInstanceOf(RecipeCardPdfGenerator::class, RecipeCardPdfGenerator::make());
    }

    #[Test]
    public function it_builds_a_deterministic_output_path_from_the_localized_name(): void
    {
        $recipe = Recipe::factory()->create(['name' => ['it' => 'Pasta al Pomodoro']]);

        $this->assertSame(
            sprintf('hellofresh/local-pdfs/pasta-al-pomodoro-%d.pdf', $recipe->id),
            $this->generator->pathFor($recipe, 'it')
        );
    }

    #[Test]
    public function it_falls_back_to_the_first_translation_when_the_locale_is_missing(): void
    {
        $recipe = Recipe::factory()->create(['name' => ['en' => 'Creamy Pasta']]);

        $this->assertSame(
            sprintf('hellofresh/local-pdfs/creamy-pasta-%d.pdf', $recipe->id),
            $this->generator->pathFor($recipe, 'it')
        );
    }

    #[Test]
    public function it_renders_the_localized_name_and_description(): void
    {
        $recipe = Recipe::factory()->create([
            'name' => ['it' => 'Pasta al Pomodoro'],
            'description' => ['it' => 'Una ricetta semplice.'],
        ]);

        $html = $this->generator->html($recipe, 'it');

        $this->assertStringContainsString('Pasta al Pomodoro', $html);
        $this->assertStringContainsString('Una ricetta semplice.', $html);
    }

    #[Test]
    public function it_embeds_local_images_as_data_uris(): void
    {
        Storage::disk('public')->put('hellofresh/recipes/recipe.jpg', 'local-image-bytes');

        $recipe = Recipe::factory()->create(['image_path' => '/recipes/recipe.jpg']);

        $html = $this->generator->html($recipe, 'it');

        $this->assertStringContainsString('data:image/', $html);
        $this->assertStringContainsString(base64_encode('local-image-bytes'), $html);
    }

    #[Test]
    public function it_silently_skips_images_that_do_not_exist_locally(): void
    {
        $recipe = Recipe::factory()->create(['image_path' => '/recipes/missing.jpg']);

        $html = $this->generator->html($recipe, 'it');

        $this->assertStringNotContainsString('<img', $html);
    }

    #[Test]
    public function it_generates_a_pdf_without_making_any_http_requests(): void
    {
        Http::fake();

        $recipe = Recipe::factory()->create();

        $path = $this->generator->generate($recipe, 'it');

        $this->assertStringStartsWith('%PDF-', (string) Storage::disk('public')->get($path));
        Http::assertNothingSent();
    }
}
