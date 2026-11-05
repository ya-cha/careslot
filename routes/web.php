<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CareAdminController;
use App\Http\Controllers\CareGroupController;
use App\Http\Controllers\ParentResponseController;
use App\Http\Middleware\PrivateCareResponse;
use App\Models\EmergencyAllocation;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');
Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/r/{token}', [ParentResponseController::class, 'show'])->middleware(['throttle:60,1', PrivateCareResponse::class])->name('parent.show');
Route::post('/r/{token}', [ParentResponseController::class, 'update'])->middleware(['throttle:20,1', PrivateCareResponse::class])->name('parent.update');

Route::middleware(['auth', 'can:viewAny,'.EmergencyAllocation::class])->group(function (): void {
    Route::middleware('can:manage-groups')->group(function (): void {
        Route::get('/gruppen', [CareGroupController::class, 'index'])->name('groups.index');
        Route::post('/gruppen', [CareGroupController::class, 'store'])->name('groups.store');
        Route::put('/gruppen/{group}', [CareGroupController::class, 'update'])->name('groups.update');
        Route::post('/gruppenadmins/neu', [CareGroupController::class, 'storeAdministrator'])->name('groups.admins.store');
        Route::post('/gruppenadmins', [CareGroupController::class, 'assignAdministrator'])->name('groups.admins');
    });
    Route::get('/dashboard', [CareAdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/kita-jahre', [CareAdminController::class, 'years'])->name('years.index');
    Route::post('/kita-jahre', [CareAdminController::class, 'storeYear'])->name('years.store');
    Route::post('/kita-jahre/{year}/archivieren', [CareAdminController::class, 'archiveYear'])->name('years.archive');
    Route::post('/kita-jahre/{year}/grundliste', [CareAdminController::class, 'initialize'])->name('years.initialize');
    Route::get('/kinder', [CareAdminController::class, 'children'])->name('children.index');
    Route::get('/kinder/neu', [CareAdminController::class, 'childForm'])->name('children.create');
    Route::post('/kinder', [CareAdminController::class, 'storeChild'])->name('children.store');
    Route::get('/kinder/{child}/bearbeiten', [CareAdminController::class, 'childForm'])->name('children.edit');
    Route::put('/kinder/{child}', [CareAdminController::class, 'updateChild'])->name('children.update');
    Route::put('/kontakte/{contact}', [CareAdminController::class, 'updateContact'])->name('contacts.update');
    Route::view('/warteliste', 'admin.waiting-list')->name('waiting-list');
    Route::get('/notbetreuung/neu', [CareAdminController::class, 'newDay'])->name('days.create');
    Route::post('/notbetreuung', [CareAdminController::class, 'storeDay'])->name('days.store');
    Route::get('/vergaben/{allocation}', [CareAdminController::class, 'show'])->name('allocations.show');
    Route::put('/vergaben/{allocation}/rueckmeldefrist', [CareAdminController::class, 'updateDeadline'])->name('allocations.deadline.update');
    Route::post('/vergaben/{allocation}/aktion', [CareAdminController::class, 'action'])->name('allocations.action');
    Route::post('/vergaben/{allocation}/neu', [CareAdminController::class, 'restart'])->name('allocations.restart');
    Route::put('/rueckmeldungen/{response}', [CareAdminController::class, 'updateResponse'])->name('responses.admin.update');
    Route::post('/elternlinks/{token}/widerrufen', [CareAdminController::class, 'revoke'])->name('tokens.revoke');
    Route::post('/versand/{delivery}/erneut', [CareAdminController::class, 'resend'])->name('mail.resend');
    Route::get('/historie', [CareAdminController::class, 'history'])->name('history');
});
