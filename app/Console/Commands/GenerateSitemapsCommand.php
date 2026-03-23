<?php

namespace App\Console\Commands;

use App\Contracts\LauncherCommandInterface;
use App\Models\Country;
use App\Models\Recipe;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'seo:generate-sitemaps')]
class GenerateSitemapsCommand extends Command implements LauncherCommandInterface
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seo:generate-sitemaps';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate sitemaps for all active countries and locales synchronously';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->ensureSitemapDirectoryExists();

        $countries = Country::query()
            ->active()
            ->orderBy('code')
            ->get();

        foreach ($countries as $country) {
            foreach ($country->locales as $locale) {
                $this->components->task(
                    "Generating sitemap for {$country->code} / {$locale}",
                    fn () => $this->generateSitemap($country, $locale)
                );
            }
        }

        $this->components->info('All sitemaps generated.');
    }

    /**
     * Generate and write the sitemap for a single country/locale combination.
     */
    protected function generateSitemap(Country $country, string $locale): void
    {
        app()->setLocale($locale);

        $xml = $this->buildSitemapXml($country, $locale);
        $filename = sprintf('sitemap-%s-%s.xml', $locale, $country->code);

        Storage::disk('public')->put('sitemaps/' . $filename, $xml);
    }

    /**
     * Build the sitemap XML content.
     */
    protected function buildSitemapXml(Country $country, string $locale): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $xml .= $this->buildUrlEntry(
            localized_route('localized.recipes.index', [], true, $country, $locale),
            now()->toDateString(),
            'daily',
            '1.0'
        );

        Recipe::where('country_id', $country->id)
            ->select(['id', 'hellofresh_id', 'name', 'updated_at'])
            ->orderBy('id')
            ->chunk(1000, function (Collection $recipes) use (&$xml, $country, $locale): void {
                foreach ($recipes as $recipe) {
                    $name = $recipe->getTranslation('name', $locale, useFallbackLocale: false)
                        ?: $recipe->getFirstTranslation('name');

                    if ($name === null) {
                        continue;
                    }

                    $slug = Str::slug($name, language: $locale);
                    $url = localized_route(
                        'localized.recipes.show',
                        ['slug' => $slug, 'recipe' => $recipe->id],
                        true,
                        $country,
                        $locale
                    );

                    $xml .= $this->buildUrlEntry(
                        $url,
                        $recipe->updated_at?->toDateString() ?? now()->toDateString(),
                        'monthly',
                        '0.8'
                    );
                }
            });

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Build a single URL entry for the sitemap.
     */
    protected function buildUrlEntry(string $loc, string $lastmod, string $changefreq, string $priority): string
    {
        return sprintf(
            "  <url>\n    <loc>%s</loc>\n    <lastmod>%s</lastmod>\n    <changefreq>%s</changefreq>\n    <priority>%s</priority>\n  </url>\n",
            htmlspecialchars($loc, ENT_XML1, 'UTF-8'),
            $lastmod,
            $changefreq,
            $priority
        );
    }

    /**
     * Ensure the sitemaps directory exists in public storage.
     */
    protected function ensureSitemapDirectoryExists(): void
    {
        if (! Storage::disk('public')->exists('sitemaps')) {
            Storage::disk('public')->makeDirectory('sitemaps');
        }
    }
}
