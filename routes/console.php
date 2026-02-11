<?php

use App\Services\BillingRenewalInvoiceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:generate-renewal-invoices', function (BillingRenewalInvoiceService $service) {
    $processed = $service->generateUpcomingRenewalInvoices(3);

    $this->info("Renewal invoices processed: {$processed}");
})->purpose('Generate renewal invoices 3/2/1 days before subscription expiration');

Schedule::command('billing:generate-renewal-invoices')->dailyAt('01:00');
