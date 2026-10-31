# HelloFresh Database

> **This is a personal fork of [Muetze42/hellofresh-database](https://github.com/Muetze42/hellofresh-database).** See [Changes from upstream](#changes-from-upstream) for what differs.

## Features from upstream

- Browse add and edit recipes from HelloFresh.es
- Filter by ingredients, allergens, tags, labels, difficulty, prep time, utensils
- Shopping list with ingredient aggregation
- Save and load shopping lists
- Create custom recipe lists
- Dark mode support

## Changes from upstream

- Focused on the Spanish HelloFresh market only — removed multi-country support from the main page due to [HelloFresh Spain's closure](https://www.hellofresh.es/pages/closure), making this a preservation archive for Spanish recipes
- Removed the Flux UI Pro dependency — replaced all Pro components with plain HTML + Tailwind CSS + Alpine.js equivalents, which caused a cascade of changes across Livewire components and Blade views
- Added an admin interface to create and edit recipes and ingredients directly in the app — this required making the entire app login-protected rather than publicly accessible as in the original
- Added a utensils filter
- Added a Cook Mode for step by step thermomix-like experience
- Added Docker setup (`docker/`)
- Added `DownloadImagesCommand` for downloading recipe images locally (for preservation in case they remove them), with options to download in steps — see [Downloading images](#downloading-images)
- Added a "Print" version that resembles the PDF from hellofresh.
- Removed the weekly menu/plan browsing feature — HelloFresh Spain will no longer publish new weekly plans following their closure
- Removed the API Portal including auth, stats, token management, and docs sections
- Removed the Personal Access Token API system (models, migrations, middleware)

## Downloading images

Images are saved to the `public` disk under `hellofresh/`. Files that already exist are skipped, so the command can be stopped and run again at any time and it continues where it left off.

```bash
docker compose exec app php artisan images:download
```

| Option | Default | Description |
|---|---|---|
| `--type=` | `all` | `all`, `recipes`, `ingredients` or `steps` |
| `--force` | off | Re-download images that already exist locally |
| `--published` | off | Only images of published recipes (applies to `recipes` and `steps`; ingredient images are not filtered) |
| `--limit=N` | none | Stop after `N` new downloads. Skipped images do not count |
| `--delay=MS` | `0` | Wait `MS` milliseconds after each download request |
| `--max-failures=N` | `5` | Abort after `N` consecutive blocked requests (HTTP 429, 403, 5xx or connection errors); `0` disables it. A 404 for a single image does not count |

To avoid being rate limited by the server, download in batches of published recipes:

```bash
docker compose exec app php artisan images:download --type=recipes --published --limit=500 --delay=300
```

Run the same command again for the next batch. The command exits with a failure code when it aborts because of `--max-failures`, so wait a while before the next run.

## Acknowledgements

Thanks to [Norman Huth (Muetze42)](https://github.com/Muetze42) for building and open-sourcing the original project that this fork is based on.
