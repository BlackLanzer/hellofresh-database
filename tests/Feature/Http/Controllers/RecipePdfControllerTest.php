<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\Country;
use App\Models\Recipe;
use App\Services\Pdf\RecipeCardPdfGenerator;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecipePdfControllerTest extends TestCase
{
    #[Test]
    public function it_serves_an_existing_local_pdf(): void
    {
        Storage::fake('public');

        $country = Country::factory()->create(['code' => 'IT', 'locales' => ['it']]);
        $recipe = Recipe::factory()->for($country)->create([
            'name' => ['it' => 'Pasta al Pomodoro'],
        ]);

        $path = RecipeCardPdfGenerator::make()->pathFor($recipe, 'it');
        Storage::disk('public')->put($path, '%PDF-1.4 existing pdf');

        $response = $this->get($this->recipePdfUrl($country, $recipe));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getFile()->getContent());
    }

    #[Test]
    public function it_generates_the_pdf_on_the_first_request_when_it_is_missing(): void
    {
        Storage::fake('public');

        $country = Country::factory()->create(['code' => 'IT', 'locales' => ['it']]);
        $recipe = Recipe::factory()->for($country)->create([
            'name' => ['it' => 'Pasta al Pomodoro'],
        ]);

        $path = RecipeCardPdfGenerator::make()->pathFor($recipe, 'it');
        Storage::disk('public')->assertMissing($path);

        $response = $this->get($this->recipePdfUrl($country, $recipe));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('%PDF-', (string) $response->getFile()->getContent());
    }

    #[Test]
    public function it_generates_a_pdf_for_a_recipe_without_a_remote_card_link(): void
    {
        Storage::fake('public');

        $country = Country::factory()->create(['code' => 'IT', 'locales' => ['it']]);
        $recipe = Recipe::factory()->for($country)->create([
            'name' => ['it' => 'Pasta al Pomodoro'],
            'card_link' => null,
        ]);

        $this->assertNull($recipe->pdf_url);

        $response = $this->get($this->recipePdfUrl($country, $recipe));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        Storage::disk('public')->assertExists(
            RecipeCardPdfGenerator::make()->pathFor($recipe, 'it')
        );
    }

    #[Test]
    public function it_resolves_the_localized_pdf_route(): void
    {
        $recipe = Recipe::factory()->create();

        $url = route('localized.recipes.pdf', [
            'locale' => 'it',
            'country' => 'IT',
            'recipe' => $recipe->id,
        ]);

        $urlPath = parse_url($url, PHP_URL_PATH);
        $this->assertIsString($urlPath);
        $this->assertStringContainsString('/recipes/', $urlPath);
        $this->assertStringEndsWith('/pdf', $urlPath);
        $this->assertSame(sprintf('/it-IT/recipes/%d/pdf', $recipe->id), $urlPath);
    }

    #[Test]
    public function its_local_path_matches_the_generator_scheme(): void
    {
        Storage::fake('public');

        $recipe = Recipe::factory()->create([
            'name' => ['it' => 'Pasta al Pomodoro'],
        ]);

        app()->setLocale('it');

        $this->assertSame(
            RecipeCardPdfGenerator::make()->pathFor($recipe, 'it'),
            $recipe->pdf_local_path
        );
        $this->assertSame(
            Storage::disk('public')->url($recipe->pdf_local_path),
            $recipe->pdf_local_url
        );
    }

    /**
     * Build the localized URL of the PDF route for the given country and recipe.
     */
    protected function recipePdfUrl(Country $country, Recipe $recipe): string
    {
        return localized_route(
            'localized.recipes.pdf',
            ['recipe' => $recipe->id],
            country: $country,
            locale: 'it'
        );
    }
}
