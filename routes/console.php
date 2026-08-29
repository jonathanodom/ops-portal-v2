<?php

use App\Jobs\QueueProposalPublicationReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new QueueProposalPublicationReminders)
    ->dailyAt('08:00')
    ->withoutOverlapping();
