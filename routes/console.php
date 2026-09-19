<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('capihouse:generate-monthly-recap')
    ->timezone('America/Sao_Paulo')
    ->dailyAt('00:05')
    ->when(fn () => now('America/Sao_Paulo')->isSameDay(now('America/Sao_Paulo')->endOfMonth()))
    ->name('capihouse-monthly-feeling-recap');
