<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Locks the `payment_policy` section across every SES contract template
 * to a single fixed cadence for this phase:
 *
 *   monthly invoicing, 7-day payment terms, bank charges + taxes on customer.
 *
 * Two changes per template's payment_policy section:
 *   1. Replace the {{payment_terms_days}} slot with the literal "7" in
 *      fixed_text so it can't be varied per draft.
 *   2. Empty the section's wizard_questions[] so the operator no longer
 *      sees the payment-terms input on step 1 of the contract wizard.
 *
 * Idempotent — re-running is a no-op once both transforms have been applied
 * (the {{payment_terms_days}} token is gone, and wizard_questions is empty).
 *
 * To re-introduce variable terms later, restore the {{payment_terms_days}}
 * token in fixed_text and re-add the wizard_question.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('contract_templates')->get(['id', 'sections', 'version']);

        foreach ($rows as $row) {
            $sections = json_decode($row->sections, true) ?: [];
            $changed = false;

            foreach ($sections as &$section) {
                if (($section['key'] ?? '') !== 'payment_policy') {
                    continue;
                }

                $original = $section['fixed_text'] ?? '';
                $locked = str_replace('{{payment_terms_days}}', '7', $original);
                if ($locked !== $original) {
                    $section['fixed_text'] = $locked;
                    $changed = true;
                }

                if (! empty($section['wizard_questions'] ?? [])) {
                    $section['wizard_questions'] = [];
                    $changed = true;
                }
            }
            unset($section);

            if ($changed) {
                DB::table('contract_templates')
                    ->where('id', $row->id)
                    ->update([
                        'sections' => json_encode($sections),
                        'version' => $row->version + 1,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Restore the {{payment_terms_days}} slot + wizard_question on every
        // template's payment_policy section.
        $rows = DB::table('contract_templates')->get(['id', 'sections', 'version']);

        foreach ($rows as $row) {
            $sections = json_decode($row->sections, true) ?: [];
            $changed = false;

            foreach ($sections as &$section) {
                if (($section['key'] ?? '') !== 'payment_policy') {
                    continue;
                }

                // Rough revert: swap the literal "within 7 days" back to the
                // slot. Doesn't try to be clever — the original phrasing
                // "Payment is payable within {{payment_terms_days}} days"
                // matches the up() target so this round-trips for the seeded
                // templates.
                $rendered = $section['fixed_text'] ?? '';
                $restored = preg_replace(
                    '/Payment is payable within 7 days/',
                    'Payment is payable within {{payment_terms_days}} days',
                    $rendered,
                );
                if ($restored && $restored !== $rendered) {
                    $section['fixed_text'] = $restored;
                    $changed = true;
                }

                $section['wizard_questions'] = [
                    [
                        'key' => 'payment_terms_days',
                        'label' => 'Payment terms (days)',
                        'type' => 'number',
                        'default' => 7,
                    ],
                ];
                $changed = true;
            }
            unset($section);

            if ($changed) {
                DB::table('contract_templates')
                    ->where('id', $row->id)
                    ->update([
                        'sections' => json_encode($sections),
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
