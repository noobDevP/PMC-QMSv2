<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\TellerController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\TvController;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Protect authenticated routes with Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Shared Admin & Lobby Routes (settings, TV media settings, ads)
    Route::middleware('role:admin,lobby')->group(function () {
        Route::get('/admin/settings', [AdminController::class, 'getSettings']);
        Route::post('/admin/settings', [AdminController::class, 'updateSettings']);
        Route::get('/admin/tv-settings/{tv_id}', [AdminController::class, 'getTvSettings']);
        Route::post('/admin/tv-settings/{tv_id}', [AdminController::class, 'updateTvSettings']);
        Route::get('/admin/ads', [AdminController::class, 'getAds']);
        Route::post('/admin/ads', [AdminController::class, 'uploadAd']);
        Route::delete('/admin/ads/{id}', [AdminController::class, 'deleteAd']);
    });

    // Strict Admin-Only Routes
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/divisions', [AdminController::class, 'getDivisions']);
        Route::post('/admin/divisions', [AdminController::class, 'createDivision']);
        Route::put('/admin/divisions/{id}', [AdminController::class, 'updateDivision']);
        Route::delete('/admin/divisions/{id}', [AdminController::class, 'deleteDivision']);
        Route::post('/admin/purposes', [AdminController::class, 'createPurpose']);
        Route::delete('/admin/purposes/{id}', [AdminController::class, 'deletePurpose']);
        Route::get('/admin/export', [AdminController::class, 'exportTickets']);
        Route::delete('/admin/resolved', [AdminController::class, 'deleteResolved']);

        // User/Teller management routes
        Route::get('/auth/users', [AuthController::class, 'getUsers']);
        Route::post('/auth/users', [AuthController::class, 'createUser']);
        Route::delete('/auth/users/{id}', [AuthController::class, 'deleteUser']);
        Route::post('/auth/users/{id}/reset', [AuthController::class, 'resetPassword']);
    });

    // Teller Routes (accessible to Teller and Admin)
    Route::middleware('role:teller,admin')->group(function () {
        Route::get('/teller/queue/{division_id}', [TellerController::class, 'getQueue']);
        Route::get('/teller/purposes', [TellerController::class, 'getPurposes']);
        Route::post('/teller/purposes', [TellerController::class, 'createPurpose']);
        Route::delete('/teller/purposes/{id}', [TellerController::class, 'deletePurpose']);
        Route::post('/teller/ticket/{id}/accept', [TellerController::class, 'acceptTicket']);
        Route::post('/teller/ticket/{id}/complete', [TellerController::class, 'completeTicket']);
        Route::get('/teller/export/{divisionId}', [TellerController::class, 'exportTickets']);
        Route::post('/teller/ticket/{id}/reroute', [TellerController::class, 'rerouteTicket']);

        // Officer management routes
        Route::get('/teller/officers', [TellerController::class, 'getOfficers']);
        Route::post('/teller/officers', [TellerController::class, 'createOfficer']);
        Route::delete('/teller/officers/{id}', [TellerController::class, 'deleteOfficer']);
    });
});

// Kiosk Routes (Public)
Route::get('/kiosk/divisions', [KioskController::class, 'getDivisions']);
Route::get('/kiosk/purposes/{division_id}', [KioskController::class, 'getPurposes']);
Route::post('/kiosk/ticket', [KioskController::class, 'createTicket'])->middleware('throttle:kiosk-ticket');
Route::post('/kiosk/ticket/{id}/cancel', [KioskController::class, 'cancelTicket'])->middleware('throttle:kiosk-cancel');

// TV Viewer Routes (Public)
Route::get('/tv/state/{tv_id}', [TvController::class, 'getState'])->middleware('throttle:tv-public');
Route::get('/tv/calendar-feed/{tv_id}', [TvController::class, 'calendarFeed'])->middleware('throttle:tv-public');
