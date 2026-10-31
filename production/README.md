# Production deployment

Self-contained production stack: PHP-FPM, nginx, PostgreSQL 17, Redis 7,
Horizon and the Laravel scheduler.

```
production/
├── docker-compose.yml         → sits next to .env so ${VAR} interpolation works
├── .env.production.example    → copy to production/.env and fill in
├── database/
│   └── hellofresh.dump        → pg_dump -Fc -Z9 (16.7 MB, 38 tables)
└── docker/
    ├── nginx/{Dockerfile,default.conf}
    └── php/{Dockerfile,Dockerfile.dockerignore,entrypoint.sh,php.ini}
```

## How this differs from the development stack

| | development | production |
|---|---|---|
| source code | bind-mounted from the host | baked into the image |
| composer | all dependencies | `--no-dev` |
| opcache | timestamp validation on | `validate_timestamps=0` + JIT |
| postgres / redis ports | published to the host | internal network only |
| `DB_PASSWORD` | falls back to `forge` | required, build fails without it |
| caches | cleared on boot | `config/route/view/event:cache` |
| nginx assets | mounted from the host | copied from the app image |

Horizon and the scheduler reuse the app image and are told apart by
`CONTAINER_ROLE`, so only the `app` container migrates and warms caches — the
workers would otherwise race against it.

## Deploy

All commands run from `production/`.

**1. Configure**

```bash
cp .env.production.example .env
```

Fill in every `CHANGE-ME`. `DB_HOST=postgres` and `REDIS_HOST=redis` must stay
as they are — they are service names on the compose network.

**2. Build**

The app image must exist before nginx, which copies `public/` out of it:

```bash
docker compose build app
```

```bash
docker compose build nginx
```

**3. Generate the application key**

```bash
docker compose run --rm app php artisan key:generate --show
```

Paste the output into `APP_KEY` in `.env`. Changing it later invalidates every
existing session and encrypted value.

**4. Start postgres and load the data**

```bash
docker compose up -d postgres
```

```bash
docker compose exec -T postgres pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --no-owner --no-privileges -j 4 < database/hellofresh.dump
```

Restore before the first `up`: the app entrypoint runs `migrate --force`, and
the dump already carries the schema at migration 37. Running it in the other
order leaves migrations half-applied.

**5. Start everything**

```bash
docker compose up -d
```

## What the dump contains

Taken after the full Italian import on 2026-08-17.

| | |
|---|---|
| recipes | 14,077 (6,873 published, 7,204 archived) |
| ingredients | 421 |
| users | 2 (password hashes included) |
| countries | 18 |
| activity_log, sessions, failed_jobs, job_batches | emptied before export |

`recipes.published = false` means archived. The recipe index hides those by
default; the "Hide Archived" switch reveals them.

## Redeploying new code

```bash
docker compose build app && docker compose build nginx
```

```bash
docker compose up -d
```

Never `docker compose restart` after a code change: with
`opcache.validate_timestamps=0` the old bytecode survives a restart. The
containers must be recreated from the new image.

## Before this is production-ready

These are deliberately left to you — I had no way to verify them from here.

- **TLS is not handled.** nginx listens on plain 80 and trusts
  `X-Forwarded-For` from `0.0.0.0/0`. Put a TLS-terminating proxy in front and
  narrow `set_real_ip_from` to that proxy's subnet, or add a certificate here.
- **Postgres is not tuned.** It runs on `postgres:17-alpine` defaults, which
  are conservative for a 94 MB database and 24 concurrent import workers.
- **No backup job.** Nothing dumps the database on a schedule.
- **Horizon has no auth gate in production.** Check `HorizonServiceProvider`
  before exposing `/horizon`.
- **The build has not been run.** I validated the compose file, but I could not
  build the images or restore the dump into a clean database from here.
