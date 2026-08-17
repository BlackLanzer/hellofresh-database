<?php

namespace App\Jobs\Recipe;

use App\Contracts\LauncherJobInterface;
use App\Enums\QueueEnum;
use App\Http\Clients\HelloFresh\HelloFreshClient;
use App\Models\Country;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * @method static void dispatch(Country $country, string $locale, int $skip = 0, bool $paginates = true, ?int $take = null, bool $includeInactive = false, bool $includeUnpublished = false)
 */
class FetchRecipePageJob implements LauncherJobInterface, ShouldQueue
{
    use Batchable;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * Create a new job instance.
     *
     * @param  bool  $includeInactive  Whether to import recipes the HelloFresh API flags as inactive
     * @param  bool  $includeUnpublished  Whether to create recipes the HelloFresh API reports as unpublished (they land archived)
     */
    public function __construct(
        public Country $country,
        public string $locale,
        public int $skip = 0,
        public bool $paginates = true,
        public ?int $take = null,
        public bool $includeInactive = false,
        public bool $includeUnpublished = false
    ) {
        $this->onQueue(QueueEnum::HelloFresh->value);

        if ($take === null) {
            $this->take = $country->take;
        }
    }

    /**
     * The console command description.
     */
    public static function description(): string
    {
        return 'Fetch a page of recipes from HelloFresh API for a specific country and locale';
    }

    /**
     * Execute the job.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function handle(HelloFreshClient $client): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Context::add([
            'country' => $this->country->id,
            'locale' => $this->locale,
            'skip' => $this->skip,
        ]);

        try {
            $response = $client->getRecipes($this->country, $this->locale, $this->skip, $this->take);
        } catch (ConnectionException|RequestException $exception) {
            $this->handleApiFailure($exception);

            return;
        }

        // Dispatch import jobs directly to their own queue (not batch)
        foreach ($response->items() as $recipe) {
            ImportRecipeJob::dispatch($this->country, $this->locale, $recipe, $this->includeInactive, $this->includeUnpublished);
        }

        // Only add next page fetch to batch if pagination is enabled
        if ($this->paginates && $response->hasMorePages()) {
            $this->batch()?->add([new self($this->country, $this->locale, $response->nextSkip(), paginates: true, take: $this->take, includeInactive: $this->includeInactive, includeUnpublished: $this->includeUnpublished)]);
        }
    }

    /**
     * Handle a failed API request with logging and retry logic.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    protected function handleApiFailure(ConnectionException|RequestException $exception): void
    {
        $isLastAttempt = $this->attempts() >= $this->tries;

        if ($isLastAttempt) {
            throw $exception;
        }

        Log::warning(static::class . ' failed, retrying', [
            'attempt' => $this->attempts(),
            'exception' => $exception->getMessage(),
        ]);

        if ($this->take > 50 && $exception instanceof RequestException && $exception->response->serverError()) {
            self::dispatch($this->country, $this->locale, $this->skip, $this->paginates, $this->take - 50, $this->includeInactive, $this->includeUnpublished);

            return;
        }

        $this->release(30);
    }
}
