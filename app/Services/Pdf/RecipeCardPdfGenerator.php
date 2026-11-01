<?php

namespace App\Services\Pdf;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use Dompdf\Dompdf;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RecipeCardPdfGenerator
{
    /**
     * The disk that stores the local images and the generated PDFs.
     */
    public const string DISK = 'public';

    /**
     * The directory on the public disk that holds generated PDFs.
     */
    public const string DIRECTORY = 'hellofresh/local-pdfs';

    /**
     * Create a new generator instance.
     */
    public static function make(): static
    {
        return new static();
    }

    /**
     * Get the deterministic output path for a recipe and locale.
     */
    public function pathFor(Recipe $recipe, string $locale): string
    {
        $name = $this->translatedValue($recipe, 'name', $locale);
        $slug = $name !== null ? trim(Str::limit(Str::slug($name), 80, ''), '-') : '';

        if ($slug === '') {
            $slug = 'recipe';
        }

        return sprintf('%s/%s-%d.pdf', self::DIRECTORY, $slug, (int) $recipe->id);
    }

    /**
     * Determine whether the PDF for a recipe and locale already exists.
     */
    public function existsFor(Recipe $recipe, string $locale): bool
    {
        return Storage::disk(self::DISK)->exists($this->pathFor($recipe, $locale));
    }

    /**
     * Render the recipe card HTML for the given locale.
     */
    public function html(Recipe $recipe, string $locale): string
    {
        /** @var View $view */
        $view = view('pdf.recipe-card', $this->viewData($recipe, $locale));

        return $view->render();
    }

    /**
     * Generate the PDF and write it to the public disk.
     *
     * @return string The path of the written file on the public disk.
     */
    public function generate(Recipe $recipe, string $locale): string
    {
        $path = $this->pathFor($recipe, $locale);
        $pdf = $this->renderPdf($this->html($recipe, $locale));

        if (! Storage::disk(self::DISK)->put($path, $pdf)) {
            throw new RuntimeException(sprintf('Unable to write the recipe card PDF to [%s].', $path));
        }

        return $path;
    }

    /**
     * Render HTML to PDF bytes with Dompdf.
     */
    protected function renderPdf(string $html): string
    {
        $dompdf = new Dompdf();
        $dompdf->setBasePath(storage_path('app/public'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdf = $dompdf->output();

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Dompdf did not return a valid PDF document.');
        }

        return $pdf;
    }

    /**
     * Build the data that is passed to the recipe card view.
     *
     * @return array{
     *     locale: string,
     *     name: string,
     *     description: string|null,
     *     countryCode: string|null,
     *     countryDomain: string|null,
     *     totalTime: int|null,
     *     prepTime: int|null,
     *     difficulty: int|null,
     *     servings: int|null,
     *     tags: list<string>,
     *     imageDataUri: string|null,
     *     ingredients: list<array{name: string, quantity: string}>,
     *     steps: list<array{index: int, instructions: string}>
     * }
     */
    protected function viewData(Recipe $recipe, string $locale): array
    {
        $recipe->loadMissing(['country', 'ingredients', 'tags']);

        $country = $recipe->country;
        $yield = $this->primaryYield($recipe);

        return [
            'locale' => $locale,
            'name' => $this->translatedValue($recipe, 'name', $locale) ?? '',
            'description' => $this->plainText($this->translatedValue($recipe, 'description', $locale)),
            'countryCode' => $country?->code,
            'countryDomain' => $country?->domain,
            'totalTime' => $recipe->total_time,
            'prepTime' => $recipe->prep_time,
            'difficulty' => $recipe->difficulty,
            'servings' => $this->servings($yield),
            'tags' => $this->tagNames($recipe, $locale),
            'imageDataUri' => $this->imageDataUri($recipe->image_path),
            'ingredients' => $this->ingredients($recipe, $yield, $locale),
            'steps' => $this->steps($recipe),
        ];
    }

    /**
     * Get the first yield entry, which is used for the printed ingredient table.
     *
     * @return array<string, mixed>|null
     */
    protected function primaryYield(Recipe $recipe): ?array
    {
        $yields = $recipe->yields_primary;

        if (! is_array($yields) || $yields === []) {
            $yields = $recipe->yields_secondary;
        }

        if (! is_array($yields) || $yields === []) {
            return null;
        }

        $yield = array_values($yields)[0];

        return is_array($yield) ? $yield : null;
    }

    /**
     * Get the number of servings from a yield entry.
     *
     * @param  array<string, mixed>|null  $yield
     */
    protected function servings(?array $yield): ?int
    {
        $value = $yield['yields'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Get the localized tag names of a recipe.
     *
     * @return list<string>
     */
    protected function tagNames(Recipe $recipe, string $locale): array
    {
        $names = [];

        foreach ($recipe->tags as $tag) {
            $name = $this->translatedValue($tag, 'name', $locale);

            if ($name === null || $name === '') {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Build the localized ingredient rows for a yield entry.
     *
     * @param  array<string, mixed>|null  $yield
     * @return list<array{name: string, quantity: string}>
     */
    protected function ingredients(Recipe $recipe, ?array $yield, string $locale): array
    {
        $items = $yield['ingredients'] ?? null;

        if (! is_array($items) || $items === []) {
            return $this->fallbackIngredients($recipe, $locale);
        }

        $map = $this->ingredientMap($recipe);
        $ingredients = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->ingredientName($item, $map, $locale);

            if ($name === null) {
                continue;
            }

            $amount = $item['amount'] ?? null;
            $unit = isset($item['unit']) && is_string($item['unit']) ? $item['unit'] : '';

            $ingredients[] = [
                'name' => $name,
                'quantity' => $this->formatQuantity(
                    is_int($amount) || is_float($amount) || is_string($amount) ? $amount : null,
                    $unit
                ),
            ];
        }

        if ($ingredients !== []) {
            return $ingredients;
        }

        return $this->fallbackIngredients($recipe, $locale);
    }

    /**
     * Build ingredient rows without quantities when no yield data is available.
     *
     * @return list<array{name: string, quantity: string}>
     */
    protected function fallbackIngredients(Recipe $recipe, string $locale): array
    {
        $ingredients = [];

        foreach ($recipe->ingredients as $ingredient) {
            $name = $this->translatedValue($ingredient, 'name', $locale);

            if ($name === null || $name === '') {
                continue;
            }

            $ingredients[] = [
                'name' => $name,
                'quantity' => '',
            ];
        }

        return $ingredients;
    }

    /**
     * Map yield ingredient references to their Eloquent models.
     *
     * @return array<string, Ingredient>
     */
    protected function ingredientMap(Recipe $recipe): array
    {
        $map = [];

        foreach ($recipe->ingredients as $ingredient) {
            $map['db-' . $ingredient->id] = $ingredient;

            $hellofreshIds = $ingredient->hellofresh_ids;

            if (! is_array($hellofreshIds)) {
                continue;
            }

            foreach ($hellofreshIds as $hellofreshId) {
                if (is_string($hellofreshId) && $hellofreshId !== '') {
                    $map[$hellofreshId] = $ingredient;
                }
            }
        }

        return $map;
    }

    /**
     * Resolve the localized name of a yield ingredient entry.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, Ingredient>  $map
     */
    protected function ingredientName(array $item, array $map, string $locale): ?string
    {
        $reference = $item['id'] ?? null;

        if (is_string($reference) && isset($map[$reference])) {
            $name = $this->translatedValue($map[$reference], 'name', $locale);

            if ($name !== null && $name !== '') {
                return $name;
            }
        }

        $name = $item['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Format an amount and unit for the ingredient table.
     */
    protected function formatQuantity(int|float|string|null $amount, string $unit): string
    {
        $parts = [];

        if (is_int($amount) || is_float($amount)) {
            $parts[] = $this->formatAmount((float) $amount);
        }

        if (is_string($amount) && trim($amount) !== '') {
            $parts[] = trim($amount);
        }

        if ($unit !== '') {
            $parts[] = $unit;
        }

        return implode(' ', $parts);
    }

    /**
     * Format an amount without trailing zeroes.
     */
    protected function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    /**
     * Build the numbered preparation steps.
     *
     * @return list<array{index: int, instructions: string}>
     */
    protected function steps(Recipe $recipe): array
    {
        $steps = $recipe->steps_primary;

        if (! is_array($steps) || $steps === []) {
            $steps = $recipe->steps_secondary;
        }

        if (! is_array($steps) || $steps === []) {
            return [];
        }

        $result = [];

        foreach (array_values($steps) as $position => $step) {
            if (! is_array($step)) {
                continue;
            }

            $instructions = $step['instructions'] ?? null;
            $text = is_string($instructions) ? $this->plainText($instructions) : null;

            if ($text === null) {
                continue;
            }

            $index = $step['index'] ?? null;

            $result[] = [
                'index' => is_numeric($index) ? (int) $index : $position + 1,
                'instructions' => $text,
            ];
        }

        return $result;
    }

    /**
     * Strip HTML and normalize whitespace so Dompdf renders plain text.
     */
    protected function plainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', $value);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * Read a locally stored recipe image and embed it as a data URI.
     */
    protected function imageDataUri(?string $imagePath): ?string
    {
        if ($imagePath === null || trim($imagePath) === '') {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $localPath = 'hellofresh/' . ltrim($imagePath, '/');

        if (! $disk->exists($localPath)) {
            return null;
        }

        $contents = $disk->get($localPath);

        if ($contents === null || $contents === '') {
            return null;
        }

        $mimeType = $disk->mimeType($localPath);
        $mimeType = is_string($mimeType) && str_starts_with($mimeType, 'image/') ? $mimeType : 'image/jpeg';

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));
    }

    /**
     * Resolve a translatable attribute for a locale, falling back to the first translation.
     *
     * @param  Recipe|Ingredient|Tag  $translatable
     */
    protected function translatedValue(Recipe|Ingredient|Tag $translatable, string $attribute, string $locale): ?string
    {
        if ($translatable->hasTranslation($attribute, $locale)) {
            return $translatable->translate($attribute, $locale);
        }

        $translations = $translatable->getTranslations($attribute);

        if ($translations === []) {
            return null;
        }

        $value = array_values($translations)[0];

        return is_string($value) ? $value : null;
    }
}
