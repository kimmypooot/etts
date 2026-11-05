# ETTS

Laravel 13 + Vue 3 + Inertia 3 + Tailwind v4 + MySQL. See `README.md` for
setup and `docs/migration-plan.md` for the rebuild plan, findings and phases.

- JavaScript only, no TypeScript. Business logic in the backend; Vue pages
  render props.
- A separate app under the IMIS hub (`../new-imis`). Staff sign in only via
  IMIS SSO (`Auth\ImisSsoController`, same contract as `../jportal`); roles
  come from IMIS `application_roles.code` (`App\Enums\UserRole`: `admin`,
  `field_office`). Never add a password sign-in for staff.
- The legacy app (`../etts`) is reference only. Never edit it. Its database
  is read through the `legacy` connection by import commands only.
- Office scoping is enforced in SQL (query scope plus policy), never only in
  the frontend. Field office users see only records of their own office.
- Applicant attachments live on the private `attachments` disk and are served
  only through an authorised, audited controller. Never put them under
  `public/`.
- Status strings come from one enum (Phase 2). Never use raw status strings
  in code or SQL.
- Tests: PHPUnit. `php artisan test --compact`.
