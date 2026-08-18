<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
/* @chisel-password-confirmation */
use Illuminate\Auth\Middleware\RequirePassword;
/* @end-chisel-password-confirmation */
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::patch('settings/security/phone', [SecurityController::class, 'updatePhone'])
        ->name('security.phone.update');

    Route::delete('settings/security/sessions', [SecurityController::class, 'destroyOtherSessions'])
        ->name('security.sessions.destroy_others');

    Route::delete('settings/security/sessions/{session_id}', [SecurityController::class, 'destroySession'])
        ->name('security.sessions.destroy');

    Route::delete('settings/security/activities', [SecurityController::class, 'destroyAllActivities'])
        ->name('security.activities.destroy_all');

    Route::delete('settings/security/activities/{activity_id}', [SecurityController::class, 'destroyActivity'])
        ->name('security.activities.destroy');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

/* @chisel-passkeys */
Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
/* @end-chisel-passkeys */
