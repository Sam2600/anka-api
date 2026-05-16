<?php

use Illuminate\Support\Facades\Schedule;

// Nightly: flip any Pending invoice whose due date has passed to Overdue.
// Keeps AR aging trustworthy on the server — frontend used to compute this
// client-side, which meant a missed page-load kept the DB lying.
Schedule::command('invoices:flip-overdue')->dailyAt('01:00')->withoutOverlapping();

// Nightly: lock schedule-tracking progress logs whose log_date is older than
// the previous working day. Employees can edit "yesterday" until end-of-day
// today; older logs become immutable history. PMs can manually unlock via
// POST /phase-progress-logs/{log}/unlock.
Schedule::command('progress-logs:lock')->dailyAt('02:00')->withoutOverlapping();
