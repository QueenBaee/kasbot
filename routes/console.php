<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('telegram:dispatch-outbox')
    ->everyMinute()->withoutOverlapping()->timezone('Asia/Jakarta');

Schedule::command('finance:notify-period-starts')
    ->dailyAt('00:00')->withoutOverlapping()->timezone('Asia/Jakarta');

Schedule::command('finance:gajian-reminder')
    ->dailyAt('08:00')->withoutOverlapping()->timezone('Asia/Jakarta');

Schedule::command('finance:daily-recap')
    ->dailyAt('20:00')->withoutOverlapping()->timezone('Asia/Jakarta');
