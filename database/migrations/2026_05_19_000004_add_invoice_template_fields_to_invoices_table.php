<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional fields required by the Invoice XLSX export template:
 *
 *   - memo                  free-text shown above the line items
 *                           ("Memo:\nThank you for your order" in the
 *                           reference template).
 *
 *   - billing_period_label  human-readable period header rendered as
 *                           the section title (e.g. "Fee for Aug 2024").
 *                           Generated from the form's billing month;
 *                           stored verbatim so historical invoices keep
 *                           the label they were issued with.
 *
 *   - line_items            JSON snapshot of the invoice's line items
 *                           at save time. Each entry: kind=resource|overhead,
 *                           label, quantity, cost, amount. Snapshotted so
 *                           editing the deal/estimation later doesn't
 *                           retroactively change an already-issued
 *                           invoice. Falls back to a live build from
 *                           the linked deal's estimation when null
 *                           (legacy invoices created before this
 *                           migration).
 *
 * (`due_date`, `issue_date`, `notes` already exist on the table.)
 *
 * Using `text` for the JSON column so SQLite tests work; Eloquent
 * casts to array. PostgreSQL stores it as text — querying inside the
 * JSON isn't required for v1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->text('memo')->nullable()->after('notes');
            $table->string('billing_period_label', 100)->nullable()->after('memo');
            $table->text('line_items')->nullable()->after('billing_period_label');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['memo', 'billing_period_label', 'line_items']);
        });
    }
};
