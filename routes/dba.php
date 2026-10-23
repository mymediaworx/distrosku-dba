<?php

use App\Http\Controllers\CheckInController;
use Illuminate\Support\Facades\Route;

// Signed instance check-ins (no session/CSRF). Body = the ERP's /dba-api/status payload.
Route::post('/api/check-in/{slug}', CheckInController::class)->where('slug', '[a-z0-9-]+');
