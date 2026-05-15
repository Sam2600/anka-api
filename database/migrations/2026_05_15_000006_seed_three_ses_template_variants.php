<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the three v1 SES template variants as global rows
 * (tenant_id = NULL). Idempotent: skips re-insert if the slug already
 * exists at the global scope.
 *
 * The `sections` JSON drives the AI contract drafting wizard:
 *   - type=fixed         → fixed_text rendered as-is
 *   - type=ai_written    → ai_prompt + wizard_questions feed Claude; AI
 *                          returns the section content as a string
 *   - type=ai_with_slots → AI-written with slot tokens like {{monthly_fee}}
 *                          which the renderer fills from deal/wizard inputs
 *   - type=slot_only     → no AI involvement; renderer fills slots from
 *                          wizard inputs or deal record only
 *
 * output_format hints the renderer/UI on layout: paragraph / bulleted_pair
 * (Provider vs User lists side-by-side) / bulleted_simple / table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ($this->variants() as $variant) {
            $exists = DB::table('contract_templates')
                ->whereNull('tenant_id')
                ->where('slug', $variant['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('contract_templates')->insert([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => null,
                'name' => $variant['name'],
                'slug' => $variant['slug'],
                'umbrella' => 'SES',
                'version' => 1,
                'sections' => json_encode($variant['sections']),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['cloud_backup', 'managed_hosting', 'engineer_dispatch'] as $slug) {
            DB::table('contract_templates')
                ->whereNull('tenant_id')
                ->where('slug', $slug)
                ->delete();
        }
    }

    /**
     * Common closing sections shared by every SES variant.
     * Boilerplate that doesn't need AI involvement.
     */
    private function commonClosingSections(): array
    {
        return [
            [
                'key' => 'payment_policy',
                'title' => 'PAYMENT POLICY',
                'type' => 'fixed',
                'fixed_text' =>
                    "(a) Provider will submit invoices to User at the end of each month.\n"
                    . "(b) Payment is payable within {{payment_terms_days}} days of the date of invoice.\n"
                    . "(c) All applicable bank charges and taxes shall be paid by the User.",
                'wizard_questions' => [
                    [
                        'key' => 'payment_terms_days',
                        'label' => 'Payment terms (days)',
                        'type' => 'number',
                        'default' => 7,
                    ],
                ],
                'output_format' => 'paragraph',
            ],
            [
                'key' => 'cancellation_fee',
                'title' => 'CANCELLATION FEE',
                'type' => 'fixed',
                'fixed_text' =>
                    "(a) User will notify Provider by official email at least one month before the break time. "
                    . "If User breaks this contract before the end of the Actual Usage Period, "
                    . "Provider may charge the remaining months as an Early Termination fee.",
                'wizard_questions' => [],
                'output_format' => 'paragraph',
            ],
        ];
    }

    /**
     * Common header section — appears in every variant.
     */
    private function descriptionSection(string $aiPrompt): array
    {
        return [
            'key' => 'description_of_services',
            'title' => 'DESCRIPTION OF SERVICES',
            'type' => 'ai_written',
            'ai_prompt' => $aiPrompt,
            'wizard_questions' => [],
            'output_format' => 'paragraph',
        ];
    }

    /**
     * Common usage period — slot-only, populated from deal record + wizard.
     */
    private function usagePeriodSection(): array
    {
        return [
            'key' => 'usage_period',
            'title' => 'USAGE PERIOD',
            'type' => 'slot_only',
            'fixed_text' =>
                "The term of this Agreement shall be {{final_contract_months}} months "
                . "starting from the Commencement Date {{commencement_date}}.\n\n"
                . "{{trial_period_clause}}\n\n"
                . "The Actual Usage Period shall start from {{actual_start_date}} "
                . "and expire on {{actual_end_date}}.",
            'wizard_questions' => [
                [
                    'key' => 'commencement_date',
                    'label' => 'Commencement date',
                    'type' => 'date',
                    'required' => true,
                ],
                [
                    'key' => 'trial_months',
                    'label' => 'Free trial duration (months)',
                    'type' => 'number',
                    'default' => 0,
                    'help' => '0 = no trial. 1 = one month free of charge.',
                ],
            ],
            'output_format' => 'paragraph',
        ];
    }

    /**
     * §7 Monitoring — slot-driven, support-hours configurable.
     */
    private function monitoringSection(string $scopeDescription): array
    {
        return [
            'key' => 'monitoring',
            'title' => 'MONITORING',
            'type' => 'slot_only',
            'fixed_text' =>
                "(a) Total service supporting hours = {{final_support_hours_per_month}} hours/month.\n"
                . "(b) Online support for technical issues during {{support_window_start}} to "
                . "{{support_window_end}} except Holidays.\n"
                . "(c) If issues are reported after {{support_window_end}}, "
                . "support will continue the next working day.\n"
                . "(d) Support and monitoring are limited to {$scopeDescription}.\n"
                . "(e) We do not support any other options.",
            'wizard_questions' => [
                [
                    'key' => 'support_window_start',
                    'label' => 'Support window start',
                    'type' => 'time',
                    'default' => '09:00 AM',
                ],
                [
                    'key' => 'support_window_end',
                    'label' => 'Support window end',
                    'type' => 'time',
                    'default' => '04:00 PM',
                ],
            ],
            'output_format' => 'paragraph',
        ];
    }

    private function variants(): array
    {
        return [
            $this->cloudBackupVariant(),
            $this->managedHostingVariant(),
            $this->engineerDispatchVariant(),
        ];
    }

    private function cloudBackupVariant(): array
    {
        return [
            'name' => 'SES — Cloud Backup Service',
            'slug' => 'cloud_backup',
            'sections' => [
                $this->descriptionSection(
                    "Write a one-paragraph DESCRIPTION OF SERVICES for a cloud backup service contract. "
                    . "Mention centralized management and secure backup with protection against unauthorized "
                    . "modification and deletion. Adapt wording to the customer's specific environment from "
                    . "the Requirement Description."
                ),
                [
                    'key' => 'services_provided',
                    'title' => 'SERVICES PROVIDED',
                    'type' => 'ai_with_slots',
                    'ai_prompt' =>
                        "Render the SERVICES PROVIDED section for a cloud backup contract. Output a "
                        . "configuration table with: backup software name + version, cloud platform "
                        . "(GCP / AWS / Azure / multi), data volume tier, retention policy. Use the "
                        . "Requirement Description to infer specifics; mark gaps as {{TODO: ...}}.",
                    'wizard_questions' => [
                        [
                            'key' => 'backup_software',
                            'label' => 'Backup software + version',
                            'type' => 'text',
                            'placeholder' => 'e.g., Veritas Backup Exec Version 22.2',
                            'required' => true,
                        ],
                        [
                            'key' => 'cloud_platform',
                            'label' => 'Cloud platform',
                            'type' => 'select',
                            'options' => ['GCP', 'AWS', 'Azure', 'Multi-cloud'],
                            'required' => true,
                        ],
                        [
                            'key' => 'data_tier_tb',
                            'label' => 'Initial data tier (TB)',
                            'type' => 'number',
                            'default' => 10,
                        ],
                    ],
                    'output_format' => 'table',
                ],
                [
                    'key' => 'scope_of_work',
                    'title' => 'SCOPE OF WORK',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "Write the SCOPE OF WORK section as TWO bulleted lists side-by-side: Provider "
                        . "(Initial + Monthly tasks) and User (Initial + Monthly tasks). Provider must cover "
                        . "cloud setup, monitoring, security. User must cover backup job creation, "
                        . "scheduling. Adapt to the Requirement Description. End with: 'We do not support "
                        . "any work that is out of scope.'",
                    'wizard_questions' => [],
                    'output_format' => 'bulleted_pair',
                ],
                [
                    'key' => 'requirements',
                    'title' => 'REQUIREMENTS',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "List the customer-side preconditions for the backup service to operate: server "
                        . "internet connectivity to the cloud platform, remote/console access, expected "
                        . "data upload time given upload speed, requirement to keep server/internet active "
                        . "during upload, day-by-day coordination during transfer. Adapt specifics from "
                        . "the Requirement Description.",
                    'wizard_questions' => [
                        [
                            'key' => 'upload_speed_mbps',
                            'label' => 'Customer upload speed (Mbps)',
                            'type' => 'number',
                            'default' => 30,
                        ],
                    ],
                    'output_format' => 'bulleted_simple',
                ],
                [
                    'key' => 'fees',
                    'title' => 'CALCULATION OF FEES AND OTHER CHARGES',
                    'type' => 'ai_with_slots',
                    'ai_prompt' =>
                        "Render the FEES section. Include: (a) one-time installation charge "
                        . "({{final_installation_fee}}) covering transportation + man-hour; (b) monthly "
                        . "cloud storage fee ({{final_monthly_fee}}) based on the initial data tier with "
                        . "tier-bump rules at higher data volumes; (c) monitoring fee structure with "
                        . "overage charges per the OT policy: {{final_ot_policy}}.",
                    'wizard_questions' => [],
                    'output_format' => 'paragraph',
                ],
                $this->usagePeriodSection(),
                $this->monitoringSection('the cloud storage bucket'),
                ...$this->commonClosingSections(),
                [
                    'key' => 'termination',
                    'title' => 'TERMINATION',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "Write the TERMINATION section as bullets covering: complete data deletion with "
                        . "evidence on request, optional data return before deletion, download duration "
                        . "based on data size + connection, customer's HDD-space obligation, storage cost "
                        . "continuing during download period, no other options provided.",
                    'wizard_questions' => [],
                    'output_format' => 'bulleted_simple',
                ],
            ],
        ];
    }

    private function managedHostingVariant(): array
    {
        return [
            'name' => 'SES — Managed Hosting / Cloud Operations',
            'slug' => 'managed_hosting',
            'sections' => [
                $this->descriptionSection(
                    "Write a one-paragraph DESCRIPTION OF SERVICES for a managed hosting / cloud "
                    . "operations contract. Mention 24/7 infrastructure monitoring, incident response, "
                    . "and SLA-backed uptime guarantees. Adapt wording to the customer's stack from the "
                    . "Requirement Description."
                ),
                [
                    'key' => 'services_provided',
                    'title' => 'SERVICES PROVIDED',
                    'type' => 'ai_with_slots',
                    'ai_prompt' =>
                        "Render SERVICES PROVIDED as a table covering: hosting platform, environment "
                        . "(prod/staging/both), SLA tier (uptime %), incident response SLA times. Use the "
                        . "Requirement Description for specifics; mark gaps as {{TODO: ...}}.",
                    'wizard_questions' => [
                        [
                            'key' => 'hosting_platform',
                            'label' => 'Hosting platform',
                            'type' => 'select',
                            'options' => ['GCP', 'AWS', 'Azure', 'Hybrid', 'On-prem'],
                            'required' => true,
                        ],
                        [
                            'key' => 'sla_uptime_pct',
                            'label' => 'SLA uptime (%)',
                            'type' => 'select',
                            'options' => ['99.0', '99.5', '99.9', '99.95'],
                            'default' => '99.5',
                        ],
                        [
                            'key' => 'environments',
                            'label' => 'Environments covered',
                            'type' => 'multiselect',
                            'options' => ['Production', 'Staging', 'Development'],
                        ],
                    ],
                    'output_format' => 'table',
                ],
                [
                    'key' => 'scope_of_work',
                    'title' => 'SCOPE OF WORK',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "Write SCOPE OF WORK as two bulleted lists: Provider (24/7 monitoring, incident "
                        . "response, patching, backup verification, capacity reviews) vs User "
                        . "(application-level support, deployment of customer-owned code, business-logic "
                        . "decisions). Adapt to the Requirement Description. End with an explicit "
                        . "out-of-scope disclaimer.",
                    'wizard_questions' => [],
                    'output_format' => 'bulleted_pair',
                ],
                [
                    'key' => 'requirements',
                    'title' => 'REQUIREMENTS',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "List customer-side preconditions: granting Provider's engineers cloud-account "
                        . "access at the agreed permission tier, providing on-call escalation contacts, "
                        . "documenting application owners for each service, change-window coordination.",
                    'wizard_questions' => [],
                    'output_format' => 'bulleted_simple',
                ],
                [
                    'key' => 'fees',
                    'title' => 'CALCULATION OF FEES AND OTHER CHARGES',
                    'type' => 'ai_with_slots',
                    'ai_prompt' =>
                        "Render FEES: (a) one-time onboarding charge ({{final_installation_fee}}); "
                        . "(b) monthly retainer ({{final_monthly_fee}}) covering the SLA-tier service; "
                        . "(c) overage handling per: {{final_ot_policy}}. Cloud-platform usage costs are "
                        . "passed through to the customer at cost.",
                    'wizard_questions' => [],
                    'output_format' => 'paragraph',
                ],
                $this->usagePeriodSection(),
                $this->monitoringSection('the agreed environments and services'),
                ...$this->commonClosingSections(),
                [
                    'key' => 'termination',
                    'title' => 'TERMINATION',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "Write TERMINATION as bullets covering: handover of monitoring dashboards and "
                        . "runbooks, knowledge-transfer session for the receiving team, access-credential "
                        . "rotation, final incident report, data migration assistance window.",
                    'wizard_questions' => [],
                    'output_format' => 'bulleted_simple',
                ],
            ],
        ];
    }

    private function engineerDispatchVariant(): array
    {
        return [
            'name' => 'SES — Engineer Dispatch',
            'slug' => 'engineer_dispatch',
            'sections' => [
                $this->descriptionSection(
                    "Write a one-paragraph DESCRIPTION OF SERVICES for a traditional SES (System "
                    . "Engineering Service) engineer dispatch contract. Mention specialist engineers "
                    . "assigned to the customer's project at a fixed monthly capacity. Adapt to the "
                    . "Requirement Description."
                ),
                [
                    'key' => 'services_provided',
                    'title' => 'SERVICES PROVIDED',
                    'type' => 'ai_with_slots',
                    'ai_prompt' =>
                        "Render SERVICES PROVIDED as a table covering: engineer count + roles, dispatch "
                        . "location (on-site / remote / hybrid), working hours per engineer per month, "
                        . "primary tech stack. Use {{final_team_summary}} from the deal and adapt specifics "
                        . "from the Requirement Description.",
                    'wizard_questions' => [
                        [
                            'key' => 'dispatch_mode',
                            'label' => 'Dispatch mode',
                            'type' => 'select',
                            'options' => ['Remote', 'On-site', 'Hybrid'],
                            'required' => true,
                        ],
                        [
                            'key' => 'monthly_hours_per_engineer',
                            'label' => 'Monthly hours per engineer',
                            'type' => 'number',
                            'default' => 160,
                        ],
                        [
                            'key' => 'working_hours_zone',
                            'label' => 'Working hours timezone',
                            'type' => 'text',
                            'placeholder' => 'e.g., MMT (UTC+6:30) 9:00-18:00',
                            'help' => 'Important for offshore — set explicitly to avoid disputes.',
                        ],
                    ],
                    'output_format' => 'table',
                ],
                [
                    'key' => 'scope_of_work',
                    'title' => 'SCOPE OF WORK',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "Write SCOPE OF WORK as bulleted_pair. Provider: engineering work within the "
                        . "agreed tech stack and project areas. User: scope-of-work decisions, "
                        . "acceptance criteria, providing necessary access/tools/test environments. Be "
                        . "explicit that work outside the agreed project scope will incur additional "
                        . "charges. Adapt from the Requirement Description.",
                    'wizard_questions' => [],
                    'output_format' => 'bulleted_pair',
                ],
                [
                    'key' => 'requirements',
                    'title' => 'REQUIREMENTS',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "List customer-side preconditions: providing development environments, source-"
                        . "code access, test data, build/deploy pipelines, designated technical "
                        . "counterpart for daily coordination. Add testing range explicitly (browsers, "
                        . "OS versions, mobile versions) if applicable.",
                    'wizard_questions' => [
                        [
                            'key' => 'testing_range',
                            'label' => 'Testing range',
                            'type' => 'text',
                            'placeholder' => 'e.g., Chrome/Firefox/Safari latest 2 versions, iOS 16+, Android 12+',
                        ],
                    ],
                    'output_format' => 'bulleted_simple',
                ],
                [
                    'key' => 'fees',
                    'title' => 'CALCULATION OF FEES AND OTHER CHARGES',
                    'type' => 'ai_with_slots',
                    'ai_prompt' =>
                        "Render FEES: (a) optional onboarding/setup fee ({{final_installation_fee}}); "
                        . "(b) monthly engineer retainer ({{final_monthly_fee}}) covering "
                        . "{{final_team_summary}}; (c) overtime / out-of-hours / out-of-scope charging "
                        . "per: {{final_ot_policy}}.",
                    'wizard_questions' => [],
                    'output_format' => 'paragraph',
                ],
                $this->usagePeriodSection(),
                $this->monitoringSection('the dispatched engineers and their assigned tasks'),
                ...$this->commonClosingSections(),
                [
                    'key' => 'termination',
                    'title' => 'TERMINATION',
                    'type' => 'ai_written',
                    'ai_prompt' =>
                        "Write TERMINATION as bullets covering: knowledge transfer to customer or "
                        . "receiving team, code/documentation handover, access-credential revocation, "
                        . "any post-engagement support window for clarifications, mutual NDA continuation "
                        . "for sensitive information.",
                    'wizard_questions' => [],
                    'output_format' => 'bulleted_simple',
                ],
            ],
        ];
    }
};
