<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EntrepriseController;
use App\Http\Controllers\API\EmployeController;
use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\AbsenceController;
use App\Http\Controllers\API\TemporaryDepartureController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public route: login
Route::post('/login', [AuthController::class, 'login']);

Route::apiResource('users', UserController::class);

// Protected routes
Route::middleware('auth.jwt')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::apiResource('entreprises', EntrepriseController::class);
    // Employés
    Route::apiResource('employes', EmployeController::class);

    // Présences
    Route::apiResource('attendances', AttendanceController::class);
    Route::post('attendances/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('attendances/check-out', [AttendanceController::class, 'checkOut']);
    Route::get('attendances/summary/{employe_id}', [AttendanceController::class, 'summary']);
    // Admin-only: marquer une arrivée "sur le terrain"
    Route::post('attendances/admin/check-in-on-field', [AttendanceController::class, 'adminCheckInOnField']);

    // Absences
    Route::apiResource('absences', AbsenceController::class);

    // Sorties temporaires
    Route::get('temporary-departures', [TemporaryDepartureController::class, 'index']);
    Route::post('temporary-departures', [TemporaryDepartureController::class, 'store']);
    Route::post('temporary-departures/{temporaryDeparture}/return', [TemporaryDepartureController::class, 'markReturn']);
    Route::get('temporary-departures/{temporaryDeparture}', [TemporaryDepartureController::class, 'show']);
    Route::put('temporary-departures/{temporaryDeparture}', [TemporaryDepartureController::class, 'update']);
    Route::delete('temporary-departures/{temporaryDeparture}', [TemporaryDepartureController::class, 'destroy']);
});
