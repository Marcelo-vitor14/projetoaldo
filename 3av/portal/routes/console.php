<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Apaga as conversas do chat paradas há mais tempo que o prazo do histórico (config/uni-search.php)
Schedule::command('model:prune')->daily();
