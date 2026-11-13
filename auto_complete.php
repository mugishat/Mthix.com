<?php
declare(strict_types=1);
// Cron (hourly): completes delivered orders the buyer never confirmed, releasing escrow.
require __DIR__ . '/../app/bootstrap.php';
echo App\Services\CheckoutService::autoComplete() . " order(s) completed\n";
