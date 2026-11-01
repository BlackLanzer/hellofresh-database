<?php

namespace App\Console\Commands;

use App\Contracts\LauncherCommandInterface;
use App\Models\Recipe;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pdfs:download')]
class DownloadPdfsCommand extends Command implements LauncherCommandInterface
{
    protected const string RESULT_DOWNLOADED = 'downloaded';

    protected const string RESULT_SKIPPED = 'skipped';

    protected const string RESULT_FAILED = 'failed';

    /**
     * Whether recipe card PDFs are limited to published recipes.
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
     * The number of new PDFs downloaded during this run.
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
    protected $signature = 'pdfs:download
                            {--force : Re-download PDFs that already exist locally}
                            {--published : Only download PDFs of published recipes}
                            {--limit= : Stop after downloading this many new PDFs}
                            {--delay=0 : Milliseconds to wait after each download request}
                            {--max-failures=5 : Abort after this many consecutive blocked or failed requests (0 to disable)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download HelloFresh recipe card PDFs to local storage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->resolveOptions()) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $this->ensureStorageLinked();

        $this->downloadPdfs($force);

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

        $this->components->info('PDF download complete.');

        return self::SUCCESS;
    }

    /**
     * Download all recipe card PDFs.
     */
    protected function downloadPdfs(bool $force): void
    {
        $this->components->info('Downloading recipe card PDFs...');

        $candidates = $this->collectCandidates();

        if ($candidates->isEmpty()) {
            $this->components->warn('No recipe card PDFs found.');

            return;
        }

        $counts = [
            self::RESULT_DOWNLOADED => 0,
            self::RESULT_SKIPPED => 0,
            self::RESULT_FAILED => 0,
        ];

        $progressBar = $this->output->createProgressBar($candidates->count());
        $progressBar->start();

        foreach ($candidates as $candidate) {
            if ($this->stopReason !== null) {
                break;
            }

            $counts[$this->downloadPdf($candidate['recipe'], $candidate['url'], $force)]++;
            $progressBar->advance();
        }

        $progressBar->finish();

        $this->newLine();
        $this->components->twoColumnDetail('Downloaded', (string) $counts[self::RESULT_DOWNLOADED]);
        $this->components->twoColumnDetail('Skipped (already exist)', (string) $counts[self::RESULT_SKIPPED]);
        $this->components->twoColumnDetail('Failed', (string) $counts[self::RESULT_FAILED]);
    }

    /**
     * Collect the downloadable recipe card PDFs.
     *
     * @return Collection<int, array{recipe: Recipe, url: string}>
     */
    protected function collectCandidates(): Collection
    {
        $candidates = collect();

        Recipe::query()
            ->when($this->onlyPublished, static fn ($query) => $query->where('published', true))
            ->whereNotNull('card_link')
            ->select(['id', 'name', 'card_link'])
            ->each(function (Recipe $recipe) use ($candidates): void {
                $url = $this->resolveCardUrl($recipe);

                if ($url === null) {
                    return;
                }

                $candidates->push([
                    'recipe' => $recipe,
                    'url' => $url,
                ]);
            });

        return $candidates;
    }

    /**
     * Resolve the single URL to download for a recipe.
     */
    protected function resolveCardUrl(Recipe $recipe): ?string
    {
        $translations = array_filter(
            $recipe->getTranslations('card_link'),
            static fn (mixed $url): bool => is_string($url) && $url !== ''
        );

        if ($translations === []) {
            return null;
        }

        $fallbackLocale = (string) config('app.fallback_locale');

        if (isset($translations[$fallbackLocale])) {
            return $translations[$fallbackLocale];
        }

        ksort($translations);

        return array_values($translations)[0];
    }

    /**
     * Get the deterministic local storage path for a recipe card PDF.
     */
    protected function localPathFor(Recipe $recipe): string
    {
        return $recipe->pdf_local_path;
    }

    /**
     * Download a single recipe card PDF and return what happened to it.
     */
    protected function downloadPdf(Recipe $recipe, string $url, bool $force): string
    {
        $localPath = $this->localPathFor($recipe);

        if (! $force && Storage::disk('public')->exists($localPath)) {
            return self::RESULT_SKIPPED;
        }

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
            $this->stopReason = sprintf('Limit of %d new PDFs reached. Run the command again to continue.', $this->limit);
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
     * Whether a failed request hints at rate limiting rather than at a single broken PDF.
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
