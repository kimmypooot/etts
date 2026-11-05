<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\ImisSsoController;
use App\Http\Controllers\Auth\ImisSsoLogoutController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DuplicateAuditController;
use App\Http\Controllers\EligibilityController;
use App\Http\Controllers\Fo;
use App\Http\Controllers\ImisSummaryController;
use App\Http\Controllers\KioskAdminController;
use App\Http\Controllers\LegacySyncController;
use App\Http\Controllers\PeopleSearchController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionDeletionController;
use App\Http\Controllers\TransactionExportController;
use App\Http\Controllers\TransactionFormController;
use App\Http\Controllers\TransactionPrintController;
use App\Http\Controllers\TransactionPulseController;
use App\Http\Controllers\TransactionStatusController;
use App\Http\Controllers\TransactionTimelineController;
use App\Http\Controllers\TransmittalController;
use App\Models\AuditLog;
use App\Models\DeletedTransaction;
use App\Models\Eligibility;
use App\Models\KioskTerminal;
use App\Models\Transaction;
use App\Models\Transmittal;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// ETTS has no public site: the staff sign in through IMIS (see ImisSsoController).
Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
});

// Not under `guest`: a token from IMIS is a new sign-in that replaces any
// session the browser still holds (see ImisSsoController).
Route::get('/sso', [ImisSsoController::class, 'show'])->middleware('throttle:30,1')->name('sso');
Route::post('/sso', [ImisSsoController::class, 'store'])->middleware('throttle:30,1')->name('sso.store');

// IMIS signs the person out of ETTS when they sign out of IMIS.
Route::post('/sso/logout', ImisSsoLogoutController::class)->middleware('throttle:60,1')->name('sso.logout');

// The Executive Dashboard feed: IMIS calls it server to server with a bearer key.
Route::get('/api/imis/summary', ImisSummaryController::class)->middleware('throttle:60,1')->name('api.imis.summary');

Route::middleware('auth')->group(function () {
    Route::get('/people/search', PeopleSearchController::class)->middleware('throttle:60,1')->name('people.search');

    Route::prefix('fo')->name('fo.')->group(function () {
        Route::get('/dashboard', Fo\DashboardController::class)->name('dashboard');

        Route::get('/transactions', [Fo\TransactionController::class, 'index'])->can('office', Transaction::class)->name('transactions.index');
        Route::get('/transactions/pulse', TransactionPulseController::class)->can('office', Transaction::class)->name('transactions.pulse');
        Route::get('/transactions/create', [Fo\TransactionController::class, 'create'])->can('createAsOffice', Transaction::class)->name('transactions.create');
        Route::post('/transactions', [Fo\TransactionController::class, 'store'])->can('createAsOffice', Transaction::class)->name('transactions.store');
        Route::post('/transactions/delete', [Fo\TransactionController::class, 'destroy'])->can('office', Transaction::class)->name('transactions.delete');
        Route::get('/transactions/{id}/timeline', Fo\TimelineController::class)->whereNumber('id')->can('office', Transaction::class)->name('transactions.timeline');
        Route::get('/transactions/{transaction}/edit', [Fo\TransactionController::class, 'edit'])->can('workAsOffice', 'transaction')->name('transactions.edit');
        Route::put('/transactions/{transaction}', [Fo\TransactionController::class, 'update'])->can('workAsOffice', 'transaction')->name('transactions.update');
        Route::post('/transactions/{transaction}/forward', [Fo\TransactionController::class, 'forward'])->can('workAsOffice', 'transaction')->name('transactions.forward');
        Route::get('/transactions/{transaction}/attachments/{slot}', [Fo\TransactionController::class, 'attachment'])->whereNumber('slot')->can('view', 'transaction')->name('attachments.show');
        Route::get('/deleted', Fo\DeletedController::class)->can('office', Transaction::class)->name('deleted');

        Route::get('/transmittals', [Fo\TransmittalController::class, 'index'])->can('office', Transmittal::class)->name('transmittals.index');
        Route::get('/transmittals/create', [Fo\TransmittalController::class, 'create'])->can('office', Transmittal::class)->name('transmittals.create');
        Route::post('/transmittals', [Fo\TransmittalController::class, 'store'])->can('office', Transmittal::class)->name('transmittals.store');
        Route::get('/transmittals/{transmittal}', [Fo\TransmittalController::class, 'show'])->can('viewAsOffice', 'transmittal')->name('transmittals.show');
        Route::get('/transmittals/{transmittal}/slip', [Fo\TransmittalController::class, 'slip'])->can('viewAsOffice', 'transmittal')->name('transmittals.slip');
        Route::get('/transmittals/{transmittal}/eligible', [Fo\TransmittalController::class, 'eligible'])->can('workAsOffice', 'transmittal')->name('transmittals.eligible');
        Route::post('/transmittals/{transmittal}/records', [Fo\TransmittalController::class, 'addRecord'])->can('workAsOffice', 'transmittal')->name('transmittals.records.add');
        Route::post('/transmittals/{transmittal}/manual', [Fo\TransmittalController::class, 'addManual'])->can('workAsOffice', 'transmittal')->name('transmittals.manual.add');
        Route::put('/transmittals/{transmittal}/manual/{item}', [Fo\TransmittalController::class, 'updateManual'])->whereNumber('item')->can('workAsOffice', 'transmittal')->name('transmittals.manual.update');
        Route::delete('/transmittals/{transmittal}/items/{item}', [Fo\TransmittalController::class, 'removeItem'])->whereNumber('item')->can('workAsOffice', 'transmittal')->name('transmittals.items.remove');
        Route::patch('/transmittals/{transmittal}/remarks', [Fo\TransmittalController::class, 'remarks'])->can('workAsOffice', 'transmittal')->name('transmittals.remarks');
        Route::patch('/transmittals/{transmittal}/records/{transaction}/note', [Fo\TransmittalController::class, 'note'])->whereNumber('transaction')->can('workAsOffice', 'transmittal')->name('transmittals.note');
        Route::post('/transmittals/{transmittal}/transmit', [Fo\TransmittalController::class, 'transmit'])->can('workAsOffice', 'transmittal')->name('transmittals.transmit');
        Route::delete('/transmittals/{transmittal}', [Fo\TransmittalController::class, 'destroy'])->can('workAsOffice', 'transmittal')->name('transmittals.destroy');
    });

    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::prefix('admin')->group(function () {
        Route::get('/transactions', [TransactionController::class, 'index'])->can('viewAny', Transaction::class)->name('transactions.index');
        Route::get('/transactions/create', [TransactionFormController::class, 'create'])->can('manage', Transaction::class)->name('transactions.create');
        Route::post('/transactions', [TransactionFormController::class, 'store'])->can('manage', Transaction::class)->name('transactions.store');
        Route::get('/transactions/pulse', TransactionPulseController::class)->can('viewAny', Transaction::class)->name('transactions.pulse');
        Route::get('/transactions/export', TransactionExportController::class)->can('export', Transaction::class)->name('transactions.export');
        Route::get('/transactions/{transaction}/edit', [TransactionFormController::class, 'edit'])->can('manage', 'transaction')->name('transactions.edit');
        Route::put('/transactions/{transaction}', [TransactionFormController::class, 'update'])->can('manage', 'transaction')->name('transactions.update');
        Route::patch('/transactions/{transaction}/quick', [TransactionFormController::class, 'quick'])->can('manage', 'transaction')->name('transactions.quick');
        Route::get('/transactions/{transaction}/errf', [TransactionPrintController::class, 'errf'])->can('manage', 'transaction')->name('transactions.print.errf');
        Route::get('/transactions/{transaction}/authenticated-copy', [TransactionPrintController::class, 'authenticatedCopy'])->can('manage', 'transaction')->name('transactions.print.auth');
        Route::get('/transactions/{id}/attachments/{slot}', AttachmentController::class)->whereNumber(['id', 'slot'])->can('viewAny', Transaction::class)->name('attachments.show');
        Route::get('/activity', [ActivityLogController::class, 'index'])->can('viewAny', AuditLog::class)->name('activity');
        Route::get('/legacy-sync', [LegacySyncController::class, 'show'])->can('sync-legacy')->name('legacy-sync');
        Route::post('/legacy-sync', [LegacySyncController::class, 'run'])->can('sync-legacy')->middleware('throttle:6,1')->name('legacy-sync.run');
        Route::get('/activity/export', [ActivityLogController::class, 'export'])->can('viewAny', AuditLog::class)->name('activity.export');
        Route::get('/transactions/{id}/timeline', TransactionTimelineController::class)->whereNumber('id')->can('viewAny', Transaction::class)->name('transactions.timeline');
        Route::post('/transactions/batch-status', [TransactionStatusController::class, 'batch'])->can('process', Transaction::class)->name('transactions.batch');
        Route::post('/transactions/delete', TransactionDeletionController::class)->can('delete', Transaction::class)->name('transactions.delete');
        Route::post('/transactions/{transaction}/forward', [TransactionStatusController::class, 'forward'])->can('process', 'transaction')->name('transactions.forward');
        Route::post('/transactions/{transaction}/receive', [TransactionStatusController::class, 'receive'])->can('process', 'transaction')->name('transactions.receive');
        Route::post('/transactions/{transaction}/status', [TransactionStatusController::class, 'update'])->can('process', 'transaction')->name('transactions.status');
        Route::post('/transactions/{transaction}/revert', [TransactionStatusController::class, 'revert'])->can('process', 'transaction')->name('transactions.revert');
        Route::get('/transmittals', [TransmittalController::class, 'index'])->can('viewAny', Transmittal::class)->name('transmittals.index');
        Route::get('/transmittals/create', [TransmittalController::class, 'create'])->can('manage', Transmittal::class)->name('transmittals.create');
        Route::post('/transmittals', [TransmittalController::class, 'store'])->can('manage', Transmittal::class)->name('transmittals.store');
        Route::get('/transmittals/{transmittal}', [TransmittalController::class, 'show'])->can('viewAny', Transmittal::class)->name('transmittals.show');
        Route::get('/transmittals/{transmittal}/slip', [TransmittalController::class, 'slip'])->can('viewAny', Transmittal::class)->name('transmittals.slip');
        Route::get('/transmittals/{transmittal}/eligible', [TransmittalController::class, 'eligible'])->can('manage', 'transmittal')->name('transmittals.eligible');
        Route::post('/transmittals/{transmittal}/records', [TransmittalController::class, 'addRecord'])->can('manage', 'transmittal')->name('transmittals.records.add');
        Route::post('/transmittals/{transmittal}/manual', [TransmittalController::class, 'addManual'])->can('manage', 'transmittal')->name('transmittals.manual.add');
        Route::put('/transmittals/{transmittal}/manual/{item}', [TransmittalController::class, 'updateManual'])->whereNumber('item')->can('manage', 'transmittal')->name('transmittals.manual.update');
        Route::delete('/transmittals/{transmittal}/items/{item}', [TransmittalController::class, 'removeItem'])->whereNumber('item')->can('manage', 'transmittal')->name('transmittals.items.remove');
        Route::patch('/transmittals/{transmittal}/remarks', [TransmittalController::class, 'remarks'])->can('manage', 'transmittal')->name('transmittals.remarks');
        Route::patch('/transmittals/{transmittal}/records/{transaction}/note', [TransmittalController::class, 'note'])->whereNumber('transaction')->can('manage', 'transmittal')->name('transmittals.note');
        Route::post('/transmittals/{transmittal}/release', [TransmittalController::class, 'release'])->can('manage', 'transmittal')->name('transmittals.release');
        Route::delete('/transmittals/{transmittal}', [TransmittalController::class, 'destroy'])->can('manage', 'transmittal')->name('transmittals.destroy');
        Route::get('/statistics', [StatisticsController::class, 'index'])->can('viewAny', Transaction::class)->name('statistics');
        Route::get('/statistics/details', [StatisticsController::class, 'details'])->can('viewAny', Transaction::class)->name('statistics.details');
        Route::get('/statistics/export', [StatisticsController::class, 'export'])->can('export', Transaction::class)->name('statistics.export');
        Route::get('/audit/duplicates', DuplicateAuditController::class)->can('viewAny', Transaction::class)->name('audit.duplicates');
        Route::get('/kiosks', [KioskAdminController::class, 'index'])->can('viewAny', KioskTerminal::class)->name('kiosks.index');
        Route::post('/kiosks/codes', [KioskAdminController::class, 'issue'])->can('manage', KioskTerminal::class)->name('kiosks.issue');
        Route::post('/kiosks/{terminal}/revoke', [KioskAdminController::class, 'revoke'])->can('manage', KioskTerminal::class)->name('kiosks.revoke');
        Route::get('/archive', [ArchiveController::class, 'index'])->can('viewAny', DeletedTransaction::class)->name('archive.index');

        Route::get('/eligibilities', [EligibilityController::class, 'index'])->can('viewAny', Eligibility::class)->name('eligibilities.index');
        Route::post('/eligibilities', [EligibilityController::class, 'store'])->can('create', Eligibility::class)->name('eligibilities.store');
        Route::put('/eligibilities/{eligibility}', [EligibilityController::class, 'update'])->can('update', 'eligibility')->name('eligibilities.update');
        Route::delete('/eligibilities/{eligibility}', [EligibilityController::class, 'destroy'])->can('delete', 'eligibility')->name('eligibilities.destroy');
        Route::get('/users', StaffController::class)->can('viewAny', User::class)->name('staff');
    });
});
