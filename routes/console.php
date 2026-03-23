<?php

// All scheduled jobs have been disabled.
// Sync jobs (recipes, menus, countries) are run on demand via `php artisan launcher`.
// Sitemap generation is run on demand via the launcher as well.
// Horizon + Redis are only needed when running sync jobs manually.

// use App\Jobs\Menu\SyncMenusJob;
// use App\Jobs\Recipe\SyncRecipesJob;
// use App\Jobs\Seo\GenerateSitemapsJob;
// use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
// use Illuminate\Support\Facades\Schedule;

// Schedule::command('horizon:snapshot')
//     ->everyFiveMinutes();

// Schedule::job(new SyncRecipesJob(true))
//     ->weekly();
// Schedule::job(new SyncRecipesJob())
//     ->daily()
//     ->days([
//         ConsoleSchedule::MONDAY,
//         ConsoleSchedule::TUESDAY,
//         ConsoleSchedule::WEDNESDAY,
//         ConsoleSchedule::THURSDAY,
//         ConsoleSchedule::FRIDAY,
//         ConsoleSchedule::SATURDAY,
//     ]);

// Schedule::job(new SyncMenusJob())
//     ->twiceDailyAt(6, 18);

// Schedule::job(new GenerateSitemapsJob())
//     ->dailyAt(12);

// Schedule::command('prune-expired-email-verifications')
//     ->daily();
// Schedule::command('queue:prune-batches --hours=48 --unfinished=72')
//     ->daily();
// Schedule::command('queue:prune-failed --hours=48')
//     ->daily();
