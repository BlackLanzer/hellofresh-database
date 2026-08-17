<?php

namespace App\Jobs\Recipe;

use App\Contracts\LauncherJobInterface;
use App\Enums\QueueEnum;
use App\Models\Country;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * @method static void dispatch(bool $fullSync = false, bool $includeInactive = false, bool $includeUnpublished = false)
 */
class SyncRecipesJob implements LauncherJobInterface, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  bool  $fullSync  Whether to paginate through all recipes (true) or only fetch the first page (false)
     * @param  bool  $includeInactive  Whether to import recipes flagged as inactive by the HelloFresh API
     * @param  bool  $includeUnpublished  Whether to create recipes the HelloFresh API reports as unpublished (they land archived)
     */
    public function __construct(
        public bool $fullSync = false,
        public bool $includeInactive = false,
        public bool $includeUnpublished = false,
    ) {
        $this->onQueue(QueueEnum::HelloFresh->value);
    }

    /**
     * The console command description.
     */
    public static function description(): string
    {
        return 'Sync recipes from HelloFresh API (daily: first page only, full: all pages)';
    }

    /**
     * Execute the job.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $countries = Country::orderBy('id')->get();

        $primaryJobs = [];
        $secondaryJobs = [];
        foreach ($countries as $country) {
            $primaryLocale = $country->locales[0] ?? null;
            if ($primaryLocale !== null) {
                $primaryJobs[] = new FetchRecipePageJob($country, $primaryLocale, paginates: $this->fullSync, includeInactive: $this->includeInactive, includeUnpublished: $this->includeUnpublished);
            }

            $secondaryLocale = $country->locales[1] ?? null;
            if ($secondaryLocale !== null) {
                $secondaryJobs[] = new FetchRecipePageJob($country, $secondaryLocale, paginates: $this->fullSync, includeInactive: $this->includeInactive, includeUnpublished: $this->includeUnpublished);
            }
        }

        Bus::batch([...$primaryJobs, ...$secondaryJobs])
            ->name('Sync HelloFresh Recipes')
            ->onQueue(QueueEnum::HelloFresh->value)
            // ->then(static function (Batch $batch): void {
            //     UpdateCountryStatisticsJob::dispatch();
            // })
            ->dispatch();
    }
}
