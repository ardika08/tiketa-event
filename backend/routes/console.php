<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler Nontix
|--------------------------------------------------------------------------
| Membatalkan order pending yang melewati batas bayar & mengembalikan kuota.
| Jalankan dengan: php artisan schedule:work (dev) atau cron (produksi).
*/
Schedule::command('nontix:expire-orders')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('nontix-expire-orders');

/*
| Rekonsiliasi status pembayaran Mayar (jaring pengaman bila webhook gagal).
*/
Schedule::command('nontix:sync-payments')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->name('nontix-sync-payments');
