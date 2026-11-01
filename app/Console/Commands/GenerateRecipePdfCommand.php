<?php

namespace App\Console\Commands;

use App\Contracts\LauncherCommandInterface;
use App\Models\Recipe;
use App\Services\Pdf\RecipeCardPdfGenerator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'pdfs:generate')]
class GenerateRecipePdfCommand extends Command implements LauncherCommandInterface
{
    protected const string RESULT_GENERATED = 'generated';

    protected const string RESULT_SKIPPED = 'skipped';

    protected const string RESULT_FAILED = 'failed';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pdfs:generate
                            {recipe? : Recipe id}
                            {--all : Generate PDFs for every published recipe}
                            {--locale=it : Locale of the rendered translations}
                            {--force : Overwrite existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate recipe card PDFs locally from stored recipe data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->resolveInput()) {
            return self::FAILURE;
        }

        $generator = RecipeCardPdfGenerator::make();
        $locale = (string) $this->option('locale');
        $force = (bool) $this->option('force');

        if ((bool) $this->option('all')) {
            return $this->generateAll($generator, $locale, $force);
        }

        return $this->generateSingle($generator, (int) $this->argument('recipe'), $locale, $force);
    }

    /**
     * Validate the command input before generating anything.
     */
    protected function resolveInput(): bool
    {
        $recipeId = $this->argument('recipe');

        if (! (bool) $this->option('all') && ($recipeId === null || $recipeId === '')) {
            $this->components->error('Provide a recipe id or use --all to generate PDFs.');

            return false;
        }

        $validator = validator(
            [
                'recipe' => $recipeId,
                'locale' => $this->option('locale'),
            ],
            [
                'recipe' => ['nullable', 'integer', 'min:1'],
                'locale' => ['required', 'string', 'min:2', 'max:10'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return false;
        }

        return true;
    }

    /**
     * Generate the PDF for a single recipe.
     */
    protected function generateSingle(RecipeCardPdfGenerator $generator, int $recipeId, string $locale, bool $force): int
    {
        $recipe = Recipe::query()
            ->with(['country', 'ingredients', 'tags'])
            ->find($recipeId);

        if (! $recipe instanceof Recipe) {
            $this->components->error(sprintf('Recipe [%d] not found.', $recipeId));

            return self::FAILURE;
        }

        try {
            $result = $this->generateForRecipe($generator, $recipe, $locale, $force);
        } catch (Throwable $exception) {
            $this->components->error(sprintf(
                'Failed to generate the PDF for recipe [%d]: %s',
                $recipeId,
                $exception->getMessage()
            ));

            return self::FAILURE;
        }

        $path = $generator->pathFor($recipe, $locale);

        if ($result === self::RESULT_SKIPPED) {
            $this->components->warn(sprintf('PDF already exists at [%s]. Use --force to overwrite.', $path));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('PDF written to [%s].', $path));

        return self::SUCCESS;
    }

    /**
     * Generate PDFs for every published recipe.
     */
    protected function generateAll(RecipeCardPdfGenerator $generator, string $locale, bool $force): int
    {
        $query = Recipe::query()
            ->where('published', true)
            ->with(['country', 'ingredients', 'tags']);

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->warn('No published recipes found.');

            return self::SUCCESS;
        }

        $counts = [
            self::RESULT_GENERATED => 0,
            self::RESULT_SKIPPED => 0,
            self::RESULT_FAILED => 0,
        ];

        $failures = [];

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $query->lazyById(200)->each(function (Recipe $recipe) use ($generator, $locale, $force, &$counts, &$failures, $progressBar): void {
            try {
                $result = $this->generateForRecipe($generator, $recipe, $locale, $force);

                if ($result === self::RESULT_SKIPPED) {
                    $counts[self::RESULT_SKIPPED]++;
                }

                if ($result === self::RESULT_GENERATED) {
                    $counts[self::RESULT_GENERATED]++;
                }
            } catch (Throwable $exception) {
                $counts[self::RESULT_FAILED]++;
                $failures[] = sprintf('Recipe [%d]: %s', (int) $recipe->id, $exception->getMessage());
            }

            $progressBar->advance();
        });

        $progressBar->finish();
        $this->newLine(2);

        foreach ($failures as $failure) {
            $this->components->error($failure);
        }

        $this->components->twoColumnDetail('Generated', (string) $counts[self::RESULT_GENERATED]);
        $this->components->twoColumnDetail('Skipped (already exist)', (string) $counts[self::RESULT_SKIPPED]);
        $this->components->twoColumnDetail('Failed', (string) $counts[self::RESULT_FAILED]);
        $this->components->info(sprintf('Processed %d published recipes.', $total));

        if ($counts[self::RESULT_FAILED] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Generate one PDF unless it already exists and overwriting was not requested.
     */
    protected function generateForRecipe(RecipeCardPdfGenerator $generator, Recipe $recipe, string $locale, bool $force): string
    {
        if (! $force && $generator->existsFor($recipe, $locale)) {
            return self::RESULT_SKIPPED;
        }

        $generator->generate($recipe, $locale);

        return self::RESULT_GENERATED;
    }
}
