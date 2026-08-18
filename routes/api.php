
use App\Http\Controllers\AuthController;

Route::post('/auth/login', [AuthController::class, 'login']);

use App\Http\Controllers\AdminController;
use App\Http\Controllers\TellerController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\TvController;

// Protect Admin and Teller routes with Sanctum
Route::middleware('auth:sanctum')->group(function () {
    // Admin Routes
    Route::get('/admin/settings', [AdminController::class, 'getSettings']);
    Route::post('/admin/settings', [AdminController::class, 'updateSettings']);
    Route::get('/admin/divisions', [AdminController::class, 'getDivisions']);
    Route::post('/admin/divisions', [AdminController::class, 'createDivision']);
    Route::put('/admin/divisions/{id}', [AdminController::class, 'updateDivision']);
    Route::delete('/admin/divisions/{id}', [AdminController::class, 'deleteDivision']);
    Route::post('/admin/purposes', [AdminController::class, 'createPurpose']);
    Route::delete('/admin/purposes/{id}', [AdminController::class, 'deletePurpose']);
    Route::get('/admin/ads', [AdminController::class, 'getAds']);
    Route::post('/admin/ads', [AdminController::class, 'uploadAd']);
    Route::delete('/admin/ads/{id}', [AdminController::class, 'deleteAd']);
    Route::get('/admin/export', [AdminController::class, 'exportTickets']);
    Route::delete('/admin/resolved', [AdminController::class, 'deleteResolved']);

    // Teller Routes
    Route::get('/teller/queue/{division_id}', [TellerController::class, 'getQueue']);
    Route::get('/teller/purposes', [TellerController::class, 'getPurposes']);
    Route::post('/teller/ticket/{id}/accept', [TellerController::class, 'acceptTicket']);
    Route::post('/teller/ticket/{id}/complete', [TellerController::class, 'completeTicket']);
    Route::get('/teller/export/{divisionId}', [TellerController::class, 'exportTickets']);
    Route::post('/teller/ticket/{id}/reroute', [TellerController::class, 'rerouteTicket']);
});

// Kiosk Routes (Public)
Route::get('/kiosk/divisions', [KioskController::class, 'getDivisions']);
Route::get('/kiosk/purposes/{division_id}', [KioskController::class, 'getPurposes']);
Route::post('/kiosk/ticket', [KioskController::class, 'createTicket']);
Route::post('/kiosk/ticket/{id}/cancel', [KioskController::class, 'cancelTicket']);

// TV Viewer Routes (Public)
Route::get('/tv/state/{tv_id}', [TvController::class, 'getState']);
