# ETTS

Examination Transaction and Tracking System for the Civil Service Commission
Regional Office VIII, Examination Services Division (ESD). It tracks
certifications, authentications and Special Eligibility Applications (SPELS)
filed at ESD and at the field and satellite offices, plus the transmittals
between them and the walk-in kiosk.

It replaces the framework-less PHP app at `../etts` (reference only, never
edit it). It is a separate app under the IMIS hub: staff sign in only through
IMIS, and IMIS grants their ETTS role. The rebuild plan, with its phases,
findings and open questions, is `docs/migration-plan.md`.

## Stack

Laravel 13, PHP 8.3+, Inertia 3 + Vue 3 (JavaScript, no TypeScript),
Tailwind v4, MySQL/MariaDB, PHPUnit.

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build        # or: npm run dev
php artisan serve --port=8080
```

Then fill in `.env`:

| Variable | What it is |
| --- | --- |
| `DB_*` | The new `etts` database |
| `LEGACY_DB_*` | A copy of the legacy database (`esd_casts_*` tables), read by the import commands only, never written to |
| `IMIS_SSO_URL` | IMIS `POST /api/v1/sso/validate`, for example `http://localhost:8000/api/v1/sso/validate` |
| `IMIS_PORTAL_URL` | IMIS home, linked from the sign-in page |
| `ATTACHMENTS_ROOT` | Private folder for applicant attachments (default `storage/app/private/attachments`) |

In IMIS: add the host and port of this app to `SSO_HUB_HOSTS`, register it as
an application pointing at `http://localhost:8080/sso`, and give it the roles
`admin` and `field_office` (the `application_roles.code` values in
`App\Enums\UserRole`).

## Tests

```bash
php artisan test --compact
```
