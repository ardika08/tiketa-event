<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Partner\EventController as PartnerEventController;
use App\Http\Controllers\Api\Partner\FormFieldController;
use App\Http\Controllers\Api\Partner\GateController;
use App\Http\Controllers\Api\Partner\PayoutController;
use App\Http\Controllers\Api\Partner\ProfileController;
use App\Http\Controllers\Api\Partner\ReportController as PartnerReportController;
use App\Http\Controllers\Api\Partner\ScanController;
use App\Http\Controllers\Api\Partner\SeatPlanController;
use App\Http\Controllers\Api\Partner\StaffController;
use App\Http\Controllers\Api\Partner\TicketTypeController;
use App\Http\Controllers\Api\Partner\UploadController;
use App\Http\Controllers\Api\Partner\VoucherController as PartnerVoucherController;
use App\Http\Controllers\Api\Public\EventController as PublicEventController;
use App\Http\Controllers\Api\Public\OrderController as PublicOrderController;
use App\Http\Controllers\Api\Public\PaymentController as PublicPaymentController;
use App\Http\Controllers\Api\Public\TicketController as PublicTicketController;
use App\Http\Controllers\Api\Public\VoucherController as PublicVoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autentikasi
|--------------------------------------------------------------------------
*/
Route::post('/auth/partner/register', [AuthController::class, 'registerPartner']);
Route::post('/auth/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Publik (tanpa login)
|--------------------------------------------------------------------------
*/
Route::get('/events', [PublicEventController::class, 'index']);
Route::get('/events/{slug}', [PublicEventController::class, 'show']);
Route::post('/vouchers/validate', [PublicVoucherController::class, 'validate']);

Route::post('/orders', [PublicOrderController::class, 'store']);
Route::get('/orders/{kodeOrder}', [PublicOrderController::class, 'show']);

Route::get('/tickets', [PublicTicketController::class, 'show']);
Route::post('/tickets/resend', [PublicTicketController::class, 'resend']);

Route::get('/payments/gateways', [PublicPaymentController::class, 'gateways']);
Route::post('/payments/callback', [PublicPaymentController::class, 'callback']);
Route::get('/payments/{kodeOrder}/status', [PublicPaymentController::class, 'status']);
Route::get('/payments/{kodeOrder}/sync', [PublicPaymentController::class, 'sync']);
Route::get('/payments/{kodeOrder}/fake', [PublicPaymentController::class, 'fake']);

/*
|--------------------------------------------------------------------------
| Terautentikasi
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    /*
    |----------------------------------------------------------------------
    | Partner
    |----------------------------------------------------------------------
    */
    Route::middleware('role:partner')->prefix('partner')->group(function () {
        Route::get('/events', [PartnerEventController::class, 'index']);
        Route::post('/events', [PartnerEventController::class, 'store']);
        Route::get('/events/{event}', [PartnerEventController::class, 'show']);
        Route::put('/events/{event}', [PartnerEventController::class, 'update']);
        Route::delete('/events/{event}', [PartnerEventController::class, 'destroy']);
        Route::post('/events/{event}/sessions', [PartnerEventController::class, 'storeSession']);
        Route::put('/events/{event}/sessions/{session}', [PartnerEventController::class, 'updateSession']);
        Route::delete('/events/{event}/sessions/{session}', [PartnerEventController::class, 'destroySession']);

        Route::get('/ticket-types', [TicketTypeController::class, 'index']);
        Route::post('/ticket-types', [TicketTypeController::class, 'store']);
        Route::get('/ticket-types/{ticketType}', [TicketTypeController::class, 'show']);
        Route::put('/ticket-types/{ticketType}', [TicketTypeController::class, 'update']);
        Route::delete('/ticket-types/{ticketType}', [TicketTypeController::class, 'destroy']);
        Route::put('/ticket-types/{ticketType}/sessions', [TicketTypeController::class, 'syncSessions']);

        Route::get('/vouchers', [PartnerVoucherController::class, 'index']);
        Route::post('/vouchers', [PartnerVoucherController::class, 'store']);
        Route::put('/vouchers/{voucher}', [PartnerVoucherController::class, 'update']);
        Route::delete('/vouchers/{voucher}', [PartnerVoucherController::class, 'destroy']);

        Route::get('/gates', [GateController::class, 'index']);
        Route::post('/gates', [GateController::class, 'store']);
        Route::put('/gates/{gate}', [GateController::class, 'update']);
        Route::delete('/gates/{gate}', [GateController::class, 'destroy']);

        Route::get('/staffs', [StaffController::class, 'index']);
        Route::post('/staffs', [StaffController::class, 'store']);
        Route::put('/staffs/{staff}', [StaffController::class, 'update']);
        Route::delete('/staffs/{staff}', [StaffController::class, 'destroy']);

        Route::get('/form-fields', [FormFieldController::class, 'index']);
        Route::post('/form-fields', [FormFieldController::class, 'store']);
        Route::put('/form-fields/{formField}', [FormFieldController::class, 'update']);
        Route::delete('/form-fields/{formField}', [FormFieldController::class, 'destroy']);

        Route::get('/events/{event}/seat-plan', [SeatPlanController::class, 'show']);
        Route::put('/events/{event}/seat-plan', [SeatPlanController::class, 'upsert']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/legal', [ProfileController::class, 'updateLegal']);

        Route::get('/analytics', [PartnerReportController::class, 'analytics']);
        Route::get('/buyers', [PartnerReportController::class, 'buyers']);
        Route::get('/finance', [PartnerReportController::class, 'finance']);
        Route::get('/sales', [PartnerReportController::class, 'sales']);

        Route::get('/payouts', [PayoutController::class, 'index']);
        Route::post('/payouts', [PayoutController::class, 'store']);
        Route::delete('/payouts/{payout}', [PayoutController::class, 'destroy']);

        Route::post('/uploads', [UploadController::class, 'store']);

        Route::get('/scan/sessions/{event}', [ScanController::class, 'sessions']);
        Route::post('/scan', [ScanController::class, 'scan']);
        Route::get('/attendance', [ScanController::class, 'attendance']);
    });

    /*
    |----------------------------------------------------------------------
    | Staff (check-in di pintu masuk)
    |----------------------------------------------------------------------
    */
    Route::middleware('role:staff,partner')->prefix('staff')->group(function () {
        Route::get('/scan/sessions/{event}', [ScanController::class, 'sessions']);
        Route::post('/scan', [ScanController::class, 'scan']);
        Route::get('/attendance', [ScanController::class, 'attendance']);
    });

    /*
    |----------------------------------------------------------------------
    | Admin Platform
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin_platform')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/partners', [AdminController::class, 'partners']);
        Route::get('/partners/{organizer}', [AdminController::class, 'partner']);
        Route::put('/partners/{organizer}', [AdminController::class, 'updatePartner']);
        Route::get('/events', [AdminController::class, 'events']);
        Route::get('/revenue', [AdminController::class, 'revenue']);
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::get('/payouts', [AdminController::class, 'payouts']);
        Route::put('/payouts/{payout}', [AdminController::class, 'updatePayout']);
        Route::post('/uploads', [AdminController::class, 'upload']);
        Route::get('/mayar/webhook', [AdminController::class, 'mayarWebhookInfo']);
        Route::post('/mayar/webhook', [AdminController::class, 'registerMayarWebhook']);
    });
});
