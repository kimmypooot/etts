# ETTS v2 cutover runbook

Goal: move ESD, the field offices and the kiosks from the legacy `/etts/` app to ETTS v2 with a short, reversible freeze. Rehearse every step on a copy first (`php artisan etts:cutover-check --local`).

## Before the day (any time earlier)

1. **Hosting**: create `etts.cscro8.com` (staff) and the kiosk host(s) (`kiosk.cscro8.com`, plus the PACD host once named) pointing at the new app's `public/` folder. Cloudflare in front, HTTPS only.
2. **Production `.env`**: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://etts.cscro8.com`, database credentials, `LEGACY_DB_*` (read only; in production the legacy `esd_casts_*` tables are in the main IMIS database `u390694310_cscro8`, not a separate `etts_legacy`), `IMIS_SSO_URL`, `IMIS_PORTAL_URL`, `ETTS_KIOSK_DOMAINS`, `TRUSTED_PROXIES`, `ATTACHMENTS_ROOT` (a folder outside the web root), optionally `IMIS_FEED_KEY`.
3. **IMIS**: register ETTS (url `https://etts.cscro8.com/sso`, roles `admin` and `field_office`), add the host to `SSO_HUB_HOSTS`.
4. **Access**: for each of the 11 ERIS Admin and 22 ERIS User holders, grant the ETTS role in IMIS (`admin` or `field_office`). Field office staff need an IMIS organisational unit that matches `offices.imis_code` (see `config/etts.php`, `imis_office_codes`). Anyone without a unit signs in with no office and sees nothing.
5. **Deploy** the code, `composer install --no-dev`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan config:cache route:cache view:cache`.
6. **UAT**: ESD and one field office each file, forward, receive, transmit and approve a few records on the rehearsal copy. Test a kiosk end to end.

## Keeping the rehearsal copy current ("Sync from legacy")

While the legacy app is still in use, the rehearsal copy goes stale. Set `ETTS_LEGACY_SYNC=true` (and the `LEGACY_DB_*` settings), then use **Settings > Sync from legacy** or `php artisan etts:sync-legacy` (add `--apply` to write; without it the command only previews). Both only read legacy and are safe to repeat:

- **Preview first**: counts what would be added, updated and left alone, writing nothing.
- A record is added when it is new, and updated when legacy changed it after this system last did; a record edited here (UAT) is never overwritten, and nothing is removed (a record deleted in legacy is only reported).
- Transmittals, their items and the activity history only gain new rows.
- It runs one table at a time, so no single request runs long; only one sync can run at once.

It does not replace the final import. On cutover day still run `etts:import-legacy-transactions --fresh`, the only step that makes the copy match legacy exactly (deletions included). **Set `ETTS_LEGACY_SYNC=false` once ETTS is live**: from then on this system is the source of truth, and the legacy copy is stale.
## Cutover day

1. Announce the freeze. Put the legacy app in maintenance (or block its write endpoints). Nothing is written to legacy after this point.
2. **Final import**: `php artisan etts:import-legacy-transactions --fresh` (asks for confirmation). It exits non-zero if any row was skipped; resolve before continuing.
3. `php artisan etts:verify-import`: every count, the amount sum and the highest id must match legacy, and there must be 0 duplicate groups.
4. **Attachments**: `rsync -a --ignore-existing <legacy>/uploads/ $ATTACHMENTS_ROOT/` then `php artisan etts:verify-attachments --list`. Nothing may be missing. Use `--quarantine` for unreferenced files (moves, never deletes).
5. `php artisan etts:cutover-check`: must say Ready. Read the warnings.
6. **Smoke test** as an administrator: dashboard, a transaction with an attachment, a transmittal, Admin, Kiosks.
7. **Kiosks**: in Admin, Kiosks create a code per terminal and enter it on the device. Check each files a test request, then delete that record with reason `TEST_DATA`.
8. Switch IMIS so the ETTS tile opens the new app. Redirect `https://imis.cscro8.com/etts/` to the new address and point the old kiosk address at the new kiosk host.
9. Announce go-live.

## First week

- Watch Activity log and the kiosk terminals' "last used".
- Keep the legacy database and `uploads/` read-only for at least a month as the rollback copy, then archive.

## Rollback

Until step 8, nothing has changed for users: stop and re-open the legacy app. After step 8: put the old redirect back, re-open legacy (it still has every record up to the freeze). Records filed in v2 after go-live exist only in v2, so export them (Transactions, Export CSV) before rolling back and re-enter them.

## Decisions and open items

- Decided: ESD needs no printable report beyond the CSV exports and the routing slip.
- Decided: the 8 ERIS holders flagged for review are not granted yet (4 inactive accounts stay out; the HRD user, the Northern Samar field head and the ESD ERIS User wait for a decision). Re-run `etts:eris-access-plan` to see them.
- Open: the PACD kiosk host name. Until set, `ETTS_KIOSK_DOMAINS` lists only `kiosk.cscro8.com`; a PACD terminal can already be paired on that host.
- Open: how field offices appear as IMIS organisational units (the 22 mapped field-office users matched on their unit codes, so this is confirmed for the current accounts).

## Access mapping (dry-run)

`php artisan etts:eris-access-plan [--csv=file]` reads the IMIS database (set `IMIS_DB_*`) and lists every ERIS access holder with the ETTS role and office each would get (ERIS Admin to `admin`, ERIS User to `field_office`, office from the IMIS unit). It grants nothing. Rows marked "review" need a decision before granting in IMIS (Admin, Systems, ETTS).

## Kiosk terminals (laptops on a counter)

The page cannot lock down the browser, so set the terminal itself up as a kiosk:
- Windows: use Assigned Access (single-app kiosk) with Edge or Chrome in kiosk mode on the kiosk address (`msedge --kiosk https://kiosk.cscro8.com --edge-kiosk-type=fullscreen`, or Chrome `--kiosk`).
- Turn off the browser's saved addresses, passwords and autofill on that profile, and sign the profile out of any account.
- Disable the address bar, new tabs, zoom and developer tools through browser policy.
- Set the display to 100% scaling. The kiosk sizes its text from the window width, so a laptop at 1366 to 1920 pixels wide needs no adjustment.
