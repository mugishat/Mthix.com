<?php
declare(strict_types=1);
// Cron (every 5 min): cancels unpaid orders whose stock reservation expired.
require __DIR__ . '/../app/bootstrap.php';
echo App\Services\CheckoutService::expireUnpaid() . " order(s) expired\n";
