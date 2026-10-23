<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\ProposalsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['ok' => true]);
Route::get('/catalog', [DraftController::class, 'catalog'])->middleware('throttle:60,1');
Route::get('/company', [ProposalsController::class, 'company'])->middleware('throttle:60,1');
Route::get('/agent/status', [AgentController::class, 'status'])->middleware('throttle:60,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware(['token', 'throttle:120,1'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/audit', [DraftController::class, 'audit']);

    Route::middleware('role:admin,bd,reviewer')->group(function () {
        Route::post('/draft', [DraftController::class, 'store'])->middleware('throttle:30,1');
        Route::post('/export', [DraftController::class, 'export'])->middleware('throttle:30,1');
        Route::post('/agent', [AgentController::class, 'send'])->middleware('throttle:30,1');
        Route::post('/agent/file', [AgentController::class, 'file'])->middleware('throttle:30,1');
        Route::post('/proposals', [ProposalsController::class, 'store']);
        Route::patch('/proposals/{proposal}', [ProposalsController::class, 'update']);
        Route::delete('/proposals/{proposal}', [ProposalsController::class, 'destroy']);
        Route::post('/proposals/{proposal}/upload', [ProposalsController::class, 'upload']);
        Route::delete('/proposals/{proposal}/file', [ProposalsController::class, 'deleteFile']);
        Route::post('/proposals/{proposal}/analyze', [ProposalsController::class, 'analyze'])->middleware('throttle:10,1');
        Route::patch('/proposals/{proposal}/items/{item}/decision', [ProposalsController::class, 'decide']);
        Route::post('/proposals/{proposal}/approve-sesuai', [ProposalsController::class, 'approveSesuai']);
        Route::get('/proposals/{proposal}/prices', [ProposalsController::class, 'prices']);
        Route::patch('/proposals/{proposal}/prices', [ProposalsController::class, 'pricesUpdate']);
        Route::post('/proposals/{proposal}/approve', [ProposalsController::class, 'approve']);
        Route::get('/proposals/{proposal}/download-check', [ProposalsController::class, 'downloadCheck']);
        Route::post('/proposals/{proposal}/preview', [ProposalsController::class, 'preview']);
        Route::post('/proposals/{proposal}/export', [ProposalsController::class, 'exportProposal']);
        Route::get('/proposals/{proposal}/versions', [ProposalsController::class, 'versions']);
        Route::get('/proposals/{proposal}/versions/{versi}/file', [ProposalsController::class, 'versionFile']);
    });
    Route::get('/proposals', [ProposalsController::class, 'index']);
    Route::get('/proposals/{proposal}', [ProposalsController::class, 'show']);
    Route::get('/proposals/{proposal}/source', [ProposalsController::class, 'source']);
    Route::get('/knowledge/quotes', [ProposalsController::class, 'quotes']);
});
