<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Localized;

use App\Models\Country;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecipeRandomTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->country = Country::factory()->create([
            'code' => 'DE',
            'locales' => ['de', 'en'],
        ]);
    }

    /**
     * Get the API base URL.
     */
    protected function apiUrl(string $path = ''): string
    {
        $apiDomain = (string) config('api.domain_name');

        return 'http://' . $apiDomain . '/' . ltrim($path, '/');
    }

    protected function apiGet(string $uri): TestResponse
    {
        return $this->getJson($this->apiUrl($uri));
    }

    #[Test]
    public function it_returns_a_recipe_from_the_requested_country(): void
    {
        $recipe = Recipe::factory()->for($this->country)->create([
            'name' => ['de' => 'Spaghetti Carbonara'],
        ]);

        $response = $this->apiGet('/de-DE/recipes/random');

        $response->assertOk()
            ->assertJsonPath('data.id', $recipe->id)
            ->assertJsonPath('data.name', 'Spaghetti Carbonara');
    }

    #[Test]
    public function it_returns_the_same_shape_as_the_show_endpoint(): void
    {
        $recipe = Recipe::factory()->for($this->country)->create();

        $random = $this->apiGet('/de-DE/recipes/random');
        $show = $this->apiGet('/de-DE/recipes/' . $recipe->id);

        $random->assertOk();
        $show->assertOk();

        self::assertSame(
            array_keys($show->json('data')),
            array_keys($random->json('data')),
        );
    }

    #[Test]
    public function it_never_returns_a_recipe_from_another_country(): void
    {
        $otherCountry = Country::factory()->create(['code' => 'AT']);
        Recipe::factory()->count(5)->for($otherCountry)->create();

        $expected = Recipe::factory()->for($this->country)->create();

        // The only candidate for DE is $expected, so every draw must return it.
        foreach (range(1, 5) as $ignored) {
            $this->apiGet('/de-DE/recipes/random')
                ->assertOk()
                ->assertJsonPath('data.id', $expected->id);
        }
    }

    #[Test]
    public function it_eventually_returns_every_recipe_of_the_country(): void
    {
        $recipeIds = Recipe::factory()
            ->count(3)
            ->for($this->country)
            ->create()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $seen = [];

        // Drawing 3 of 3 distinct ids has a vanishing failure rate over 60 attempts.
        foreach (range(1, 60) as $ignored) {
            $seen[] = $this->apiGet('/de-DE/recipes/random')->assertOk()->json('data.id');

            if (count(array_unique($seen)) === count($recipeIds)) {
                break;
            }
        }

        $seen = array_values(array_unique($seen));
        sort($seen);

        self::assertSame($recipeIds, $seen);
    }

    #[Test]
    public function it_returns_404_when_the_country_has_no_recipes(): void
    {
        $this->apiGet('/de-DE/recipes/random')->assertNotFound();
    }

    #[Test]
    public function it_returns_404_for_an_unknown_country(): void
    {
        Recipe::factory()->for($this->country)->create();

        $this->apiGet('/de-XX/recipes/random')->assertNotFound();
    }
}
