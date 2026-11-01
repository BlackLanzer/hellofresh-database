<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Recipe;
use App\Services\Pdf\RecipeCardPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GenerateRecipePdfCommandTest extends TestCase
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
    public function it_generates_a_pdf_for_a_single_recipe(): void
    {
        $recipe = Recipe::factory()->create();

        $this->artisan('pdfs:generate', ['recipe' => $recipe->id])->assertSuccessful();

        $path = $this->generator->pathFor($recipe, 'it');

        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk('public')->get($path));
    }

    #[Test]
    public function it_generates_pdfs_for_published_recipes_only_with_the_all_option(): void
    {
        $published = Recipe::factory()->create(['published' => true]);
        $unpublished = Recipe::factory()->create(['published' => false]);

        $this->artisan('pdfs:generate', ['--all' => true])->assertSuccessful();

        Storage::disk('public')->assertExists($this->generator->pathFor($published, 'it'));
        Storage::disk('public')->assertMissing($this->generator->pathFor($unpublished, 'it'));
    }

    #[Test]
    public function it_does_not_overwrite_an_existing_pdf_without_the_force_option(): void
    {
        $recipe = Recipe::factory()->create();
        $path = $this->generator->pathFor($recipe, 'it');

        Storage::disk('public')->put($path, 'placeholder');

        $this->artisan('pdfs:generate', ['recipe' => $recipe->id])->assertSuccessful();

        $this->assertSame('placeholder', Storage::disk('public')->get($path));
    }

    #[Test]
    public function it_overwrites_an_existing_pdf_with_the_force_option(): void
    {
        $recipe = Recipe::factory()->create();
        $path = $this->generator->pathFor($recipe, 'it');

        Storage::disk('public')->put($path, 'placeholder');

        $this->artisan('pdfs:generate', ['recipe' => $recipe->id, '--force' => true])->assertSuccessful();

        $this->assertStringStartsWith('%PDF-', (string) Storage::disk('public')->get($path));
    }

    #[Test]
    public function it_fails_when_neither_a_recipe_nor_the_all_option_is_given(): void
    {
        $this->artisan('pdfs:generate')
            ->expectsOutputToContain('Provide a recipe id or use --all')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_for_an_unknown_recipe_id(): void
    {
        $this->artisan('pdfs:generate', ['recipe' => 999_999])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_for_an_invalid_recipe_id(): void
    {
        $this->artisan('pdfs:generate', ['recipe' => 'invalid'])->assertFailed();
    }
}
