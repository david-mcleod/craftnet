<?php

// Only run scheduled jobs on production
if (!defined('CRAFT_ENVIRONMENT') || CRAFT_ENVIRONMENT !== 'prod') {
    return;
}

$deliveryEmail = 'devops@pixelandtonic.com';

/** @var $schedule omnilight\scheduling\Schedule */

$schedule->command('craftnet/licenses/send-reminders')
    ->daily()
    ->withoutOverlapping()
    ->sendOutputTo('/var/app/current/cron/licenses-send-reminders.log')
    ->emailOutputTo([$deliveryEmail]);

$schedule->command('craftnet/licenses/process-expired-licenses')
    ->daily()
    ->withoutOverlapping()
    ->sendOutputTo('/var/app/current/cron/licenses-process-expired-licenses.log')
    ->emailOutputTo([$deliveryEmail]);

$schedule->command('craftnet/packages/update-deps --queue')
    ->daily()
    ->withoutOverlapping()
    ->sendOutputTo('/var/app/current/cron/packages-update-deps.log')
    ->emailOutputTo([$deliveryEmail]);

$schedule->command('craftnet/plugins/update-install-counts')
    ->daily()
    ->withoutOverlapping()
    ->sendOutputTo('/var/app/current/cron/plugins-update-install-counts.log')
    ->emailOutputTo([$deliveryEmail]);
