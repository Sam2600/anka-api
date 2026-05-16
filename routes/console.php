<?php

use Illuminate\Support\Facades\Schedule;

// Nightly: flip any Pending invoice whose due date has passed to Overdue.
// Keeps AR aging trustworthy on the server — frontend used to compute this
// client-side, which meant a missed page-load kept the DB lying.
Schedule::command('invoices:flip-overdue')->dailyAt('01:00')->withoutOverlapping();

// Nightly: auto-activate signed contracts whose start_date has arrived, and
// auto-complete active contracts whose project has burned through its
// budget_hours. Time-entry approvals also trigger the completion check in
// real time — this command is the date-driven sibling. See
// storage/contract_auto_status_decision.md for the why.
Schedule::command('contracts:auto-transition')->dailyAt('01:30')->withoutOverlapping();
