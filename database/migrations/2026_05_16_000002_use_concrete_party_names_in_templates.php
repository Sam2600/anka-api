<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replace the abstract "Provider" / "User" literals in the SES template
 * fixed/slot_only sections with `{{provider_name}}` and `{{customer_name}}`
 * slot tokens. ContractDraftService::resolveSlots() fills these per draft
 * with the tenant's name + deal's client name, so the contract body now
 * uses the actual parties rather than abstract roles.
 *
 * Touches: payment_policy, cancellation_fee, usage_period, monitoring
 * (sections shared across all 3 SES variants via commonClosingSections /
 * usagePeriodSection / monitoringSection helpers in the seed migration).
 *
 * Idempotent — only updates rows whose section text still contains the
 * old literals. Re-runnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('contract_templates')->whereNull('tenant_id')->get(['id', 'sections', 'version']);

        foreach ($rows as $row) {
            $sections = json_decode($row->sections, true) ?: [];
            $changed = false;

            foreach ($sections as &$section) {
                $key = $section['key'] ?? '';
                if (! in_array($key, ['payment_policy', 'cancellation_fee', 'usage_period', 'monitoring'], true)) {
                    continue;
                }

                $text = $section['fixed_text'] ?? '';
                if ($text === '') {
                    continue;
                }

                $updated = $this->rewriteText($text);
                if ($updated !== $text) {
                    $section['fixed_text'] = $updated;
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
        // Down migrations for content rewrites are inherently lossy
        // (any subsequent template edits would be reverted). Treat as
        // irreversible at the data layer.
    }

    /**
     * Replace standalone "Provider" and "User" with slot tokens. Uses
     * word boundaries so e.g. "providers" or "users" inside other words
     * stay intact. The seeded SES text only uses these as standalone
     * party names, so this rewrite is safe.
     */
    private function rewriteText(string $text): string
    {
        $text = preg_replace('/\bProvider\b/', '{{provider_name}}', $text) ?? $text;
        $text = preg_replace('/\bUser\b/', '{{customer_name}}', $text) ?? $text;
        return $text;
    }
};
