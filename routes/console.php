<?php

use Illuminate\Support\Facades\Schedule;

// Nightly: flip any Pending invoice whose due date has passed to Overdue.
// Keeps AR aging trustworthy on the server — frontend used to compute this
// client-side, which meant a missed page-load kept the DB lying.
Schedule::command('invoices:flip-overdue')->dailyAt('01:00')->withoutOverlapping();
