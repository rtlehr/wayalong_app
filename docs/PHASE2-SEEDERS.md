# WayAlong — Phase 2 Step 3A Sample Seeders

These seeders are **explicitly invoked**; the default `DatabaseSeeder` is not changed.
They refuse to run outside `local` or `testing` environments. They use only fictional
names, non-deliverable `@wayalong.test` email addresses, and sample facility addresses.
No patient or insurance data is generated.

## Development database

```powershell
php artisan db:seed --class="Database\Seeders\DevelopmentSeeder"
```

Three organizations, five facilities, and five sample users are created. One
organization is inactive; memberships include multi-organization role differences
and an inactive staff membership.

## QA database (use a separate non-production database)

```powershell
php artisan db:seed --class="Database\Seeders\QATestSeeder"
```

Three organizations, four facilities, and six stable QA personas are created.
QA includes owner, admin, clinician, staff, deactivated membership, and a user
with no memberships. QA Alpha and Beta each have a `MAIN` facility code to
exercise organization-level uniqueness.

## Local sample credentials

Users in these datasets end in `@wayalong.test`. Their initial password is
`WayAlong-Local-Only-2026!`, unless you set `WAYALONG_SEED_PASSWORD` in your
local `.env` before the first run. Accounts have verified email timestamps.
**Never use these credentials on public deployments or on real accounts.**
Re-running a seeder will *not* reset existing users' passwords. For QA personas,
search by email prefix `qa.`; for development personas, search by `dev.`.

## Test

```powershell
php artisan test tests/Feature/Authorization/SampleSeederTest.php
php artisan test
```

The seeder updates its own canonical sample records on re-run without duplicating
organization memberships, facilities, or accounts. It does not delete other data.
It is intentionally not invoked automatically by `php artisan migrate`.
