# Issuance Processing Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the admin edit form for Certification / Authentication records with a 5-step processing page that saves per step and finishes with print, VCAS, Mark completed and Release.

**Architecture:** A thin `TransactionProcessController` over two new services (`ProcessSteps` for step rules, `VcasLink` for the hand-off URL) and the existing `TransactionRecorder::update()` / `TransactionWorkflow`. Each step save merges the step's validated fields into the record's current values (from `TransactionFormPresenter::edit`) and calls `update()`, so pricing, auto-receive, optimistic lock and audit are reused. Vue renders props only.

**Tech Stack:** Laravel 13, Inertia 3, Vue 3 (JavaScript only), Tailwind v4, PHPUnit on SQLite `:memory:`.

**Spec:** `docs/superpowers/specs/2026-11-13-process-transaction-design.md`

## Global Constraints

- JavaScript only, no TypeScript. Business logic in the backend; Vue pages render props.
- Status values only via `App\Enums\TransactionStatus`; never raw status strings in PHP.
- Every mutation and every staff read of applicant data is audited (`AuditLog::record`).
- Attachments only through the private `attachments` disk and the audited `attachments.show` route.
- Keep existing branding, `StaffLayout`, components and `@theme` tokens; do not copy the legacy visual design.
- No browser `alert`/`confirm`; confirmations use `@/Components/Feedback/Modal.vue`.
- Tests: `php artisan test --compact`; style: `vendor/bin/pint --dirty`; frontend: `npm run build` must pass.
- Commits: `git -c user.name=maicoo.soledad -c user.email=maicoo.soledad@gmail.com commit`, message ending `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never stage `package-lock.json`.

## Review Focus

1. A step save posted for a SPELS record (direct POST) must 404, not save through the issuance path. → test in Task 4.
2. A step-4 type change to `Special Eligibility Application` must be refused by validation, not reach the recorder. → test in Task 4.
3. `?step=5` on a record whose steps 1–3 are incomplete must open the first incomplete step, not skip validation. → test in Task 4.
4. A VCAS value containing a newline and a slash together (`"BLK 1/LOT 2\nBRGY X"`) must become one encoded field. → test in Task 2.
5. Saving a step with no changes must still succeed and keep the version usable for the next save (version returned is the fresh `updated_at`). → test in Task 4.

---

### Task 1: `ProcessSteps` service

**Files:**
- Create: `app/Services/Transactions/ProcessSteps.php`
- Test: `tests/Feature/Transactions/ProcessStepsTest.php` (Feature, so factories and the DB are available)

**Interfaces:**
- Produces (all `public static`):
  - `fields(int $step): list<string>` — step 1–4 field names exactly as in spec §3.3.
  - `rules(int $step, bool $othersPurpose = false): array<string, array>` — format rules copied from `TransactionFormRequest::rules()` for that step's fields, with `required` replacing `nullable` for the spec's required fields; step 4 also has `transaction_type => ['required', Rule::in(issuance type values)]`, `official_receipt` required, `coe_copies`/`auth_copies` `['required','integer','min:0','max:99']`; `purpose_others` required when `$othersPurpose`.
  - `missing(int $step, array $values): list<string>` — required fields of that step that are blank in `$values` (step 4 also reports `coe_copies` when `coe_copies + auth_copies < 1`).
  - `firstIncompleteStep(array $values): int` — first of 1–4 with `missing()` non-empty, else 5.
  - `isLocked(Transaction $t): bool` — status in Completed, Released, Cancelled.
  - `finishAction(Transaction $t): ?string` — `'complete'` when Received, `'release'` when Completed, else null.
- `$values` is the `record` array from `TransactionFormPresenter::edit()`.

- [ ] **Step 1: Write failing tests**
  - `test_step_fields_match_the_spec`: `fields(3)` === `['book','page','sequence','examinee_no','cert_no','rating','release_date','effectivity_date']`.
  - `test_missing_reports_required_blanks`: `missing(1, ['lname'=>'Cruz','fname'=>'','purpose_of_request'=>null])` === `['fname','purpose_of_request']`; `missing(1, [... 'purpose_of_request'=>'Others','purpose_others'=>''])` contains `'purpose_others'`; `missing(3, [all but effectivity_date filled])` === `[]`.
  - `test_step_four_needs_a_copy_and_an_or`: `missing(4, ['transaction_type'=>'Certification','coe_copies'=>0,'auth_copies'=>0,'official_receipt'=>''])` === `['official_receipt','coe_copies']`.
  - `test_first_incomplete_step`: all of 1–2 filled, 3 missing `book` → 3; everything filled → 5.
  - `test_lock_and_finish_action_follow_the_status`: Submitted → unlocked, null; Received → unlocked, `'complete'`; Completed → locked, `'release'`; Released and Cancelled → locked, null.
- [ ] **Step 2: Run** `php artisan test --compact --filter=ProcessStepsTest` — expect FAIL (class not found).
- [ ] **Step 3: Implement** `ProcessSteps` with the signatures above. Keep the field lists and required lists as `private const` arrays.
- [ ] **Step 4: Run** the filter again — expect PASS.
- [ ] **Step 5: Commit** `Add ProcessSteps rules for the issuance processing page`.

### Task 2: `VcasLink` builder

**Files:**
- Create: `app/Services/Transactions/VcasLink.php`
- Test: `tests/Feature/Transactions/VcasLinkTest.php`

**Interfaces:**
- Produces: `public static function for(array $values): string` — `$values` is the presenter `record` array. Returns `'vcas://'.implode('/', $encoded)`.
- Field order (29 keys, spec §3.6): `transaction_type, lname, fname, mname, extn, date_of_birth, place_of_birth, sex, civil_status, current_address, employment_status, company_name, company_address, purpose_of_request, used_lastname, used_firstname, used_middlename, used_extn, eligibility, date_of_exam_issuance, place_of_exam_issuance, book, page, sequence, examinee_no, cert_no, rating, release_date, effectivity_date`.
- `purpose_of_request` is sent as stored: when it is `'Others'`, send `'Others: '.$values['purpose_others']`.
- Per value: trim, collapse `\s*\R\s*` to one space, empty → `NA`, then `rawurlencode`.

- [ ] **Step 1: Write failing tests**
  - `test_it_sends_29_values_in_the_legacy_order`: build a full `$values`; `explode('/', substr($link, 7))` has 29 items; item 0 === `rawurlencode('Certification')`, item 1 === `'CRUZ'`, item 26 (rating) === `'85.5'`.
  - `test_empty_values_become_na`: all blank → every item === `'NA'`.
  - `test_slashes_and_newlines_never_shift_fields`: `current_address = "BLK 1/LOT 2\nBRGY X"` → still 29 items; item 9 === `'BLK%201%2FLOT%202%20BRGY%20X'`.
  - `test_others_purpose_is_sent_with_its_text`: purpose `'Others'`, others `'Visa'` → item 13 === `rawurlencode('Others: Visa')`.
- [ ] **Step 2: Run** `php artisan test --compact --filter=VcasLinkTest` — FAIL.
- [ ] **Step 3: Implement** `VcasLink::for` with the order as a `private const FIELDS`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Add VcasLink builder with per-value encoding`.

### Task 3: Recorder upload cleanup and form-update lock

**Files:**
- Modify: `app/Services/Transactions/TransactionRecorder.php` (`update()`, lines ~128–191)
- Modify: `app/Http/Controllers/TransactionFormController.php` (`update()`)
- Test: `tests/Feature/Transactions/TransactionFormTest.php`

**Interfaces:**
- Consumes: `ProcessSteps::isLocked(Transaction): bool` (Task 1); `AttachmentStore::forget(string $name)`.
- Produces: `TransactionRecorder::update()` unchanged signature; deletes files it stored in that call if anything throws.

- [ ] **Step 1: Write failing tests**
  - `test_a_failed_update_leaves_no_uploaded_file_behind`: see Step 3 for how the failure is forced after the file is stored; assert session has `error` and `Storage::disk('attachments')->allFiles()` === `[]`.
  - `test_a_completed_or_released_certification_cannot_be_edited_through_the_form`: for Completed and Released cert records, PUT valid cert input → session `error`, `lname` unchanged.
- [ ] **Step 2: Run** `php artisan test --compact --filter=TransactionFormTest` — the two new tests FAIL.
- [ ] **Step 3: Implement**
  - In `update()`: collect names returned by `$this->attachments->store()` into `$stored`; wrap the `DB::transaction` in `try/catch (\Throwable)` that forgets them and rethrows (same shape as `create()`). To force a failure after the file is stored, the test binds a `TransactionWorkflow` mock (`$this->mock(TransactionWorkflow::class)`) whose `receive()` throws `WorkflowException`, then PUTs a Submitted cert with an OR and `attachment_1 = UploadedFile::fake()->image('id.jpg')`. A stale version would fail before storing and prove nothing.
  - In `TransactionFormController::update()`: before calling the recorder, if `$transaction->transaction_type->isIssuance() && ProcessSteps::isLocked($transaction)`, return `refuse(new WorkflowException('This record is completed or closed and can no longer be edited. Revert it first to correct it.'))`.
- [ ] **Step 4: Run** the filter — PASS (all existing form tests too).
- [ ] **Step 5: Commit** `Clean up uploads on a failed update; lock finished issuance records`.

### Task 4: Processing controller, routes and edit redirect

**Files:**
- Create: `app/Http/Controllers/TransactionProcessController.php`
- Create: `app/Http/Requests/ProcessStepRequest.php`
- Modify: `routes/web.php` (admin group, next to `transactions.edit` at line ~110)
- Modify: `app/Http/Controllers/TransactionFormController.php` (`edit()`)
- Test: `tests/Feature/Transactions/TransactionProcessTest.php`; update `test_the_edit_form_opens_with_the_record_its_copies_and_a_version` in `TransactionFormTest.php` to use a SPELS record or assert the redirect.

**Interfaces:**
- Consumes: Task 1 `ProcessSteps`, Task 2 `VcasLink::for`, `TransactionFormPresenter('/admin/transactions', 'attachments.show')->edit($t)` → `['record','attachments','version','status','options',...]`, `TransactionRecorder::update()`.
- Routes (all `->can('manage', 'transaction')`):
  - `GET /transactions/{transaction}/process` → `show`, name `transactions.process`
  - `POST /transactions/{transaction}/process/{step}` → `save`, `->where('step', '[1-4]')`, name `transactions.process.save`
  - `POST /transactions/{transaction}/process/vcas` → `vcas`, name `transactions.process.vcas`
- `show(Request, Transaction): Response` — 404 for SPELS. Renders `Transactions/Process` with props:
  - `id, status, version, record, attachments, options` (from presenter)
  - `steps`: `list<{number:int, label:string, missing:list<string>}>` for 1–4 plus `{number:5,label:'Print & finish',missing:[]}`; labels `Personal info, Eligibility, Verification, Payment & files, Print & finish`
  - `step`: requested `?step` clamped to `1..firstIncompleteStep`; default `firstIncompleteStep`
  - `locked: bool`, `finish: ?string` (`finishAction`), `vcasUrl: ?string` (only when type contains Certification), `office: string`, `base: '/admin/transactions'`
- `ProcessStepRequest`: `authorize()` true (route gate does it); `rules()` = `ProcessSteps::rules((int) $this->route('step'), $this->input('purpose_of_request') === 'Others') + ['version' => ['nullable','string','max:19']]`; step 4 adds the two attachment rules from `TransactionFormRequest`.
- `save(ProcessStepRequest, Transaction, int $step): RedirectResponse` — 404 for SPELS; if `isLocked` → back with error; else `$values = [...presenter->edit($t)['record'], ...$request->safe()->only(ProcessSteps::fields($step))]`, call `update($t, $values, $user, $request->input('version'), files when step 4)`; on success redirect to `transactions.process` with `step = $step + 1` and status `"Step {$step} saved."`; on `WorkflowException` back with input and error.
- `vcas(Transaction): RedirectResponse` — 404 for SPELS; `AuditLog::record('transaction.vcas_sent', $t, after: ['summary' => 'Sent the record to VCAS.'])`; `back()` (Inertia posts expect a redirect; the project has no axios).
- `TransactionFormController::edit()`: issuance → `redirect()->route('transactions.process', $transaction)`.

- [ ] **Step 1: Write failing tests** (setUp like `TransactionFormTest`: offices 1 ESD, 2 FO; admin `esd.officer`; `Storage::fake('attachments')`; helper `issuance(array $record = [], array $issuance = [], TransactionStatus $status = Received)` creating a Certification with an `IssuanceRequest`)
  - `test_an_issuance_record_opens_the_processing_page_and_spels_does_not`: GET process → component `Transactions/Process`; SPELS → 404; FO user → 403.
  - `test_edit_redirects_issuance_records_and_still_opens_spels`.
  - `test_the_page_opens_on_the_first_incomplete_step`: steps 1–2 complete, `book` blank → `step` 3; `?step=5` → still 3; all complete → 5; `?step=2` with all complete → 2.
  - `test_each_step_enforces_its_required_fields`: POST step 2 with empty `eligibility` → errors `eligibility`; step 4 with zero copies → errors `coe_copies`; step 4 with `transaction_type = 'Special Eligibility Application'` → errors `transaction_type`.
  - `test_a_step_save_keeps_the_other_steps_values`: record with `company_address = 'Tacloban City Hall'`; POST step 3 → `company_address` unchanged, `book` saved; redirect to `?step=4`.
  - `test_a_save_without_changes_succeeds_and_returns_a_fresh_version`: POST step 1 with current values twice, the second with the version from the reloaded page props → both redirect with `status`.
  - `test_a_stale_version_is_refused`: POST step 1 with `version = '2000-01-01 00:00:00'` → session `error`.
  - `test_saving_the_or_in_step_four_receives_and_assigns_the_officer`: Submitted cert, POST step 4 with OR → status Received, `action_officer` `'esd.officer'`.
  - `test_locked_records_refuse_step_saves`: Completed / Released / Cancelled → session `error`, values unchanged.
  - `test_the_finish_action_and_vcas_link_follow_the_record`: Received cert → `finish` `'complete'`, `vcasUrl` starts `vcas://`; Authentication → `vcasUrl` null; Completed → `finish` `'release'`, `locked` true.
  - `test_a_step_save_on_a_spels_record_is_not_found`.
  - `test_sending_to_vcas_is_audited`: POST vcas → redirect; `AuditLog` row with action `transaction.vcas_sent` for the record.
- [ ] **Step 2: Run** `php artisan test --compact --filter=TransactionProcessTest` — FAIL.
- [ ] **Step 3: Implement** the request, controller, routes and the `edit()` redirect per the interfaces. Update the existing edit-form test.
- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/Transactions` — PASS. (`config/inertia.php` has `ensure_pages_exist => false`, so the page file can come in Task 5.)
- [ ] **Step 5: Commit** `Add the issuance processing controller, routes and edit redirect`.

### Task 5: Processing page (Vue)

**Files:**
- Create: `resources/js/Pages/Transactions/Process.vue`
- Create: `resources/js/Components/Process/StepBar.vue`, `PersonalStep.vue`, `EligibilityStep.vue`, `VerificationStep.vue`, `PaymentStep.vue`, `FinishStep.vue`, `AttachmentsPanel.vue`
- Create: `resources/js/Components/Transactions/SignatoryPicker.vue` (extracted from `Form.vue` lines 32–40 and 286–298)
- Modify: `resources/js/Pages/Transactions/Form.vue` (use `SignatoryPicker`)
- Modify: `resources/js/Pages/Transactions/Index.vue:392` (Edit href → `/process` when `row.type !== 'Special Eligibility Application'`)

**Interfaces:**
- Consumes: Task 4 props (`id, status, version, record, attachments, options, steps, step, locked, finish, vcasUrl, office, base`); routes `transactions.process.save`, `.vcas`, existing `/{id}/status` (body `{status: 'Completed'|'Released', date?}`), `/{id}/errf`, `/{id}/authenticated-copy?signatory=&position=&office=`.
- Step components: props `form` (the page's `useForm` object), `options`, `readonly: boolean`; they render fields only (reuse `Field`, `FormGroup`, `Select`, `FileInput`, `PersonInput`; option lists as in `Form.vue`).
- `StepBar`: props `steps`, `current`, `reachable` (number); emits `go(n)`; each step a `<button>` with `aria-current="step"` on the active one; unreached steps disabled.
- `SignatoryPicker`: `v-model` object `{name, position, office}`; default office `'Examination Services Division'`.
- `FinishStep`: props `type, status, finish, vcasUrl, hasOriginal, base, id`; Mark completed / Release open a `Modal` confirm, then `router.post(`${base}/${id}/status`, {status})`; Release on success `router.visit(base)`. VCAS button: `router.post(vcas route, {}, { preserveScroll: true, preserveState: true, onSuccess: () => (window.location.href = vcasUrl) })`.
- Page behaviour: one `useForm` holding `record` + `version`; **Next** posts the current step's fields (`forceFormData` on step 4); **Back** moves without saving; switching steps with `form.isDirty` opens a confirm `Modal` ("Discard changes to this step?"); `readonly` when `locked`, with an `Alert` "This record is {status}. Revert it on the board to correct it."; header shows name (`personName`), type badge, office, status badge, OR; step 4 shows the price preview `coe × prices.coe + auth × prices.auth`; `or_date` defaults to today when empty.
- Layout: `lg:grid lg:grid-cols-[1fr_22rem] gap-6`; attachments column stacks below on small screens.

- [ ] **Step 1: Extract `SignatoryPicker`** and switch `Form.vue` to it; `npm run build` passes.
- [ ] **Step 2: Build the components and `Process.vue`** per the interfaces; update the Index Edit link.
- [ ] **Step 3: Run** `npm run build` (no errors) and `php artisan test --compact` (all pass).
- [ ] **Step 4: Commit** `Add the issuance processing page`.

### Task 6: Browser verification

**Files:** none (test record created and removed in the local DB).

- [ ] **Step 1:** As dev admin on `http://etts.test`, create a Certification and Authentication test record `ZZPROCESS` (Submitted, no OR, no issuance fields) via tinker.
- [ ] **Step 2:** From `/admin/transactions`, click Edit → lands on `/process` at step 1. Fill and Next through steps 1–3; on step 4 enter copies, OR, upload a JPG as Attachment 2 → status becomes Received, step 5 opens.
- [ ] **Step 3:** Step 5: ERRF print opens; Process COE logs `transaction.vcas_sent` in the Activity log (the `vcas://` launch itself may show a browser "no handler" prompt — acceptable); authenticated copy prints with a signatory; Mark completed → steps become read-only, Release shown; Release → back on the board.
- [ ] **Step 4:** Check a SPELS row's Edit still opens the old form; check console has no errors.
- [ ] **Step 5:** Delete `ZZPROCESS` and its issuance row and uploaded file; run `php artisan test --compact` and `vendor/bin/pint --dirty` one last time.
