<?php

use Illuminate\Foundation\Inspiring;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
use App\Modules\Catalog\Console\ImportProductsCsvCommand;
use Illuminate\Console\Application as ArtisanApplication;

ArtisanApplication::starting(function (ArtisanApplication $artisan): void {
    $artisan->resolve(ImportProductsCsvCommand::class);
});
