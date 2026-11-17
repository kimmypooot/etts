# Session log: 2026-11-15 to 2026-11-16

Kiosk confirmation, the Q8 integration assessment, and the "Transaction Action
Buttons and Behaviors by Office and Status" spec (audit, then implementation).
All work is on `main`, committed, **not pushed** (27 commits ahead of
`origin/main` at the end of the session). 446 tests pass.

## 1. CLAUDE.md review (`/init`)

The existing CLAUDE.md was already accurate; no changes then. (It was updated
later in the session; see section 6.)

## 2. Q8 queuing system: assessment only, not integrated

Q8 (`../Q8`) is a separate Laravel 13 + Inertia app: PACD issues numbers, ESD
windows call them, TV display with voice and videos, reports, backups. About
13k lines, 7 phases done, waiting for owner acceptance.

**Recommendation given: keep Q8 separate.** Reasons:
- Q8 must work offline on the office LAN; ETTS is hosted online (Hostinger,
  Cloudflare). Inside ETTS the queue would stop when the internet is down.
- Q8 needs Reverb (always-on WebSocket) and a scheduler every minute; shared
  hosting may not run them (not verified).
- Q8 signs in with username and password; ETTS rule is IMIS SSO only.
- Q8's queue correctness relies on MySQL locking (`SKIP LOCKED`) and
  real-process race tests; ETTS tests run on SQLite.
- Merging would be a rewrite, add heavy dependencies to every ETTS deploy,
  and reopen a finished project.

If a link is wanted later: a per-office, off-by-default switch in the ETTS
kiosk; Q8 pulls kiosk submissions (Q8 is on the LAN, so ETTS cannot call it),
or the PACD clerk types the ETTS number into Q8.

**Decision (user): do not integrate.**

## 3. Kiosk confirmation page (`4b3889d`, `5fa3538`)

- Removed the tracking number ticket and "Keep this number..." from
  `Kiosk/Done.vue`.
- Steps now read "Please wait for your priority number to be called at the
  Counter." and "Please ensure that you bring a valid ID and have all the
  required documents are complete." (wording as given by the user; flagged as
  ungrammatical, kept on request).
- `Kiosk\RequestController` sends only `type`, `first_name`, `spels` to the
  confirmation.
- The user's own wording changes to `Kiosk/Menu.vue` and `Kiosk/Request.vue`
  were committed separately (`5fa3538`), with `docs/PROCESS_TRANSACTION_FLOW.md`
  (`3e91d44`) and `package-lock.json` (`03f9319`, npm side effect: package name
  `etts-v2` to `etts`, optional `react`; revert if unwanted).
- Note: `etts.test` serves `public/build`; run `npm run build` after Vue
  changes or the old page shows.

## 4. Audit against the action-buttons spec

The user supplied a spec of buttons per portal (ESD, field office), type
(certification/authentication, SPELS) and status. The audit found ESD
cert/auth mostly compliant and SPELS plus the whole FO side not. Main gaps:
no OR check on Forward to RO, no Document Logistics panel, no read-only SPELS
form, no FO View Details, no FO processing page, and an FO print section that
404'd.

## 5. Implementation (three commits)

### `77ea2de` Forward only once paid
- `TransactionWorkflow::forward()` refuses a SPELS application without an OR
  number (blank counts as none); FO-to-RO transmittals offer and transmit
  only paid applications.

### `77d83e0` FO View Details, read-only once final
Decisions (user, 2026-11-16):
- **A field office works on a record only while it is Submitted.**
- **Returned is final for the office** (no edit, delete, re-forward or
  transmittal; the applicant files a new one).
- **A received certification is no longer editable or deletable by the office.**

Built: `/fo/transactions/{id}` (View Details, `TransactionPolicy::viewAsOffice`);
`App\Support\ProcessPage` (processing page props, shared); a record in a final
status opens read-only and cannot be saved (`TransactionFormPresenter::isLocked`,
used by `ProcessSteps::isLocked`); the FO form no longer shows Printing.

### `bbc8033` Buttons by office and status, Document Logistics, FO Receive/Return
- `App\Services\Transactions\TransactionActions`: the one place the button
  matrix lives. Both boards render it in one Actions column
  (`RowActions.vue`, `ActionDialog.vue`). `availableActions()` removed.
- Matrix:

| Portal | Type | Status | Buttons |
|---|---|---|---|
| ESD | Cert/Auth | Submitted, Received | Process, Cancel |
| ESD | Cert/Auth | Completed | Mark as Released, View Details, Revert |
| ESD | Cert/Auth | Released | View Details, Revert |
| ESD | SPELS | Submitted at ESD, Forwarded | Receive, Return |
| ESD | SPELS | Received | Approve, Disapprove, Return |
| ESD | SPELS | Approved, Disapproved, Returned | View Details |
| ESD | SPELS | Submitted at an FO, paid | Forward to RO (on the office's behalf) |
| FO | Cert/Auth | Submitted | Receive (attachments only), Cancel |
| FO | Cert/Auth | later | View Details |
| FO | SPELS | Submitted, no OR | Receive (Payment first), Return |
| FO | SPELS | Submitted, with OR | Forward to RO |
| FO | SPELS | later | View Details |

  Every row also has Logs/History. Revert goes one step back
  (Released to Completed, Completed to Received).
- ESD Receive (SPELS): edit page, Payment first; "Save and receive" needs
  the OR number.
- A SPELS application is received only once paid, and may be Returned from
  Submitted or Forwarded (its equivalent of Cancel).
- FO Receive (cert/auth): `/fo/transactions/{id}/process`, attachments only,
  saved by `TransactionRecorder::replaceAttachments`. The office no longer
  enters a certification's OR number; ESD does.
- FO Return (SPELS, unpaid): `/fo/transactions/{id}/return`, reason required.
- Document Logistics panel (`DocumentLogistics` service and component) on
  every SPELS page: filed, paid, forwarded (batch no.), received (by whom),
  decision, released back (batch no.).
- FO Delete moved into the history window.
- Removed from the board: "Mark approved and released" (an ESD transmittal
  release still sets Approved/Released).

## Open items

- Push `main` (user's call).
- The field office portal was not checked in a browser (needs a field office
  sign-in); covered by tests only. ESD board, SPELS Receive page and the
  Completed row were checked; no console errors.
- Confirm the judgement calls: no board edit for a Received SPELS at ESD
  (revert first); ESD forwarding on an office's behalf kept; FO Delete in the
  history window.
- Pint flags 18 files that were already non-compliant before this session.
- Legacy records forwarded without an OR number now need one before ESD can
  receive them.

## Environment notes

- Git commits: `git -c user.name=maicoo.soledad -c user.email=maicoo.soledad@gmail.com`.
- The shell collapses `\\` to `\` in inline scripts and heredocs: write
  scripts to a file with the Write tool, or use the Edit tool for PHP
  namespaces.
- `npm run build` after Vue changes; `etts.test` serves the built assets.
