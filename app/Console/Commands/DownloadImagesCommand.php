<?php

namespace App\Console\Commands;

use App\Contracts\LauncherCommandInterface;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'images:download')]
class DownloadImagesCommand extends Command implements LauncherCommandInterface
{
    protected const string RESULT_DOWNLOADED = 'downloaded';

    protected const string RESULT_SKIPPED = 'skipped';

    protected const string RESULT_FAILED = 'failed';

    /**
     * The transformation to apply when downloading recipe images.
     */
    protected string $recipeTransformation = 'w_1200,q_auto,f_jpg,fl_lossy';

    /**
     * The transformation to apply when downloading ingredient images.
     */
    protected string $ingredientTransformation = 'w_200,q_auto,f_png';

    /**
     * The transformation to apply when downloading step images.
     */
    protected string $stepTransformation = 'w_600,q_auto,f_jpg,fl_lossy';

    /**
     * Whether recipe and step images are limited to published recipes.
     */
    protected bool $onlyPublished = false;

    /**
     * The number of new downloads after which the run stops, or null for no limit.
     */
    protected ?int $limit = null;

    /**
     * The pause after every download request, in milliseconds.
     */
    protected int $delayMs = 0;

    /**
     * The number of consecutive blocked requests after which the run aborts, or 0 to never abort.
     */
    protected int $maxFailures = 5;

    /**
     * The number of new images downloaded during this run.
     */
    protected int $downloadedTotal = 0;

    /**
     * The number of blocked or failed requests in a row.
     */
    protected int $consecutiveFailures = 0;

    /**
     * Why the run stopped early, or null if it ran to the end.
     */
    protected ?string $stopReason = null;

    /**
     * Whether the run stopped early because the server seems to block the requests.
     */
    protected bool $aborted = false;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:download
                            {--type=all : The type of images to download (all, recipes, ingredients, steps)}
                            {--force : Re-download images that already exist locally}
                            {--published : Only download images of published recipes (recipes and steps)}
                            {--limit= : Stop after downloading this many new images}
                            {--delay=0 : Milliseconds to wait after each download request}
                            {--max-failures=5 : Abort after this many consecutive blocked or failed requests (0 to disable)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download HelloFresh recipe and ingredient images to local storage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->resolveOptions()) {
            return self::FAILURE;
        }

        $type = $this->option('type');
        $force = (bool) $this->option('force');

        $this->ensureStorageLinked();

        match ($type) {
            'recipes' => $this->downloadRecipeImages($force),
            'ingredients' => $this->downloadIngredientImages($force),
            'steps' => $this->downloadStepImages($force),
            default => $this->downloadAll($force),
        };

        return $this->reportOutcome();
    }

    /**
     * Validate the throttling options and keep their values.
     */
    protected function resolveOptions(): bool
    {
        $validator = validator(
            [
                'limit' => $this->option('limit'),
                'delay' => $this->option('delay'),
                'max-failures' => $this->option('max-failures'),
            ],
            [
                'limit' => ['nullable', 'integer', 'min:1'],
                'delay' => ['required', 'integer', 'min:0'],
                'max-failures' => ['required', 'integer', 'min:0'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return false;
        }

        $limit = $this->option('limit');

        $this->onlyPublished = (bool) $this->option('published');
        $this->limit = $limit === null ? null : (int) $limit;
        $this->delayMs = (int) $this->option('delay');
        $this->maxFailures = (int) $this->option('max-failures');

        return true;
    }

    /**
     * Tell how the run ended and return the matching exit code.
     */
    protected function reportOutcome(): int
    {
        if ($this->aborted) {
            $this->components->error($this->stopReason);

            return self::FAILURE;
        }

        if ($this->stopReason !== null) {
            $this->components->warn($this->stopReason);

            return self::SUCCESS;
        }

        $this->components->info('Image download complete.');

        return self::SUCCESS;
    }

    /**
     * Download all image types.
     */
    protected function downloadAll(bool $force): void
    {
        $this->downloadRecipeImages($force);

        if ($this->stopReason !== null) {
            return;
        }

        $this->downloadIngredientImages($force);

        if ($this->stopReason !== null) {
            return;
        }

        $this->downloadStepImages($force);
    }

    /**
     * Download all recipe images.
     */
    protected function downloadRecipeImages(bool $force): void
    {
        $this->components->info('Downloading recipe images...');

        $paths = Recipe::query()
            ->when($this->onlyPublished, static fn ($query) => $query->where('published', true))
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->unique()
            ->values();

        $this->downloadImages($paths, $this->recipeTransformation, $force);
    }

    /**
     * Download all ingredient images.
     */
    protected function downloadIngredientImages(bool $force): void
    {
        $this->components->info('Downloading ingredient images...');

        $paths = Ingredient::query()
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->unique()
            ->values();

        $this->downloadImages($paths, $this->ingredientTransformation, $force);
    }

    /**
     * Download all step images from recipe JSON columns.
     */
    protected function downloadStepImages(bool $force): void
    {
        $this->components->info('Downloading step images...');

        $paths = $this->collectStepImagePaths();

        $this->downloadImages($paths, $this->stepTransformation, $force);
    }

    /**
     * Collect all unique image paths from recipe step JSON columns.
     *
     * @return Collection<int, string>
     */
    protected function collectStepImagePaths(): Collection
    {
        $paths = collect();

        Recipe::query()
            ->when($this->onlyPublished, static fn ($query) => $query->where('published', true))
            ->where(function ($query): void {
                $query->whereNotNull('steps_primary')
                    ->orWhereNotNull('steps_secondary');
            })
            ->select(['steps_primary', 'steps_secondary'])
            ->each(function (Recipe $recipe) use (&$paths): void {
                $paths = $paths->merge($this->extractStepPaths($recipe->steps_primary));
                $paths = $paths->merge($this->extractStepPaths($recipe->steps_secondary));
            });

        return $paths->unique()->values();
    }

    /**
     * Extract image paths from a steps array.
     *
     * @param  array<int, array<string, mixed>>|null  $steps
     * @return Collection<int, string>
     */
    protected function extractStepPaths(?array $steps): Collection
    {
        if ($steps === null || $steps === []) {
            return collect();
        }

        return collect($steps)
            ->flatMap(function (array $step): array {
                /** @var array<int, array<string, string>> $images */
                $images = $step['images'] ?? [];

                return array_filter(
                    array_column($images, 'path'),
                    static fn (mixed $path): bool => is_string($path) && $path !== ''
                );
            });
    }

    /**
     * Download a collection of images to local storage.
     *
     * @param  Collection<int, string>  $paths
     */
    protected function downloadImages(Collection $paths, string $transformation, bool $force): void
    {
        if ($paths->isEmpty()) {
            $this->components->warn('No images found.');

            return;
        }

        $counts = [
            self::RESULT_DOWNLOADED => 0,
            self::RESULT_SKIPPED => 0,
            self::RESULT_FAILED => 0,
        ];

        $progressBar = $this->output->createProgressBar($paths->count());
        $progressBar->start();

        foreach ($paths as $imagePath) {
            if ($this->stopReason !== null) {
                break;
            }

            $counts[$this->downloadImage($imagePath, $transformation, $force)]++;
            $progressBar->advance();
        }

        $progressBar->finish();

        $this->newLine();
        $this->components->twoColumnDetail('Downloaded', (string) $counts[self::RESULT_DOWNLOADED]);
        $this->components->twoColumnDetail('Skipped (already exist)', (string) $counts[self::RESULT_SKIPPED]);
        $this->components->twoColumnDetail('Failed', (string) $counts[self::RESULT_FAILED]);
    }

    /**
     * Download a single image and return what happened to it.
     */
    protected function downloadImage(string $imagePath, string $transformation, bool $force): string
    {
        $localPath = 'hellofresh' . $imagePath;

        if (! $force && Storage::disk('public')->exists($localPath)) {
            return self::RESULT_SKIPPED;
        }

        $url = $this->buildDownloadUrl($imagePath, $transformation);

        try {
            $response = Http::timeout(30)->get($url);
        } catch (ConnectionException) {
            $this->registerFailure(null);
            $this->pause();

            return self::RESULT_FAILED;
        }

        $this->pause();

        if (! $response->successful()) {
            $this->registerFailure($response->status());

            return self::RESULT_FAILED;
        }

        Storage::disk('public')->put($localPath, $response->body());

        $this->registerDownload();

        return self::RESULT_DOWNLOADED;
    }

    /**
     * Count a new download and stop the run once the limit is reached.
     */
    protected function registerDownload(): void
    {
        $this->downloadedTotal++;
        $this->consecutiveFailures = 0;

        if ($this->limit !== null && $this->downloadedTotal >= $this->limit) {
            $this->stopReason = sprintf('Limit of %d new images reached. Run the command again to continue.', $this->limit);
        }
    }

    /**
     * Count a failed request and abort the run when the server seems to block it.
     */
    protected function registerFailure(?int $status): void
    {
        if (! $this->isBlockingFailure($status)) {
            return;
        }

        $this->consecutiveFailures++;

        if ($this->maxFailures > 0 && $this->consecutiveFailures >= $this->maxFailures) {
            $this->aborted = true;
            $this->stopReason = sprintf(
                'Aborted after %d consecutive blocked or failed requests. Wait a while, then run the command again to continue.',
                $this->consecutiveFailures
            );
        }
    }

    /**
     * Whether a failed request hints at rate limiting rather than at a single missing image.
     */
    protected function isBlockingFailure(?int $status): bool
    {
        return $status === null || $status === 429 || $status === 403 || $status >= 500;
    }

    /**
     * Wait between two requests.
     */
    protected function pause(): void
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
    }

    /**
     * Build a CDN download URL for the given image path and transformation.
     */
    protected function buildDownloadUrl(string $imagePath, string $transformation): string
    {
        $baseUrl = config('hellofresh.cdn.base_url');
        $bucket = config('hellofresh.cdn.bucket');

        return $baseUrl . '/' . $transformation . '/' . $bucket . $imagePath;
    }

    /**
     * Ensure the public storage symlink exists.
     */
    protected function ensureStorageLinked(): void
    {
        $publicPath = public_path('storage');

        if (! file_exists($publicPath)) {
            $this->callSilent('storage:link');
        }
    }
}
