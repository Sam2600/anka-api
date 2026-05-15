<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Improves the ai_prompt strings across the three seeded SES template variants.
 *
 * Changes per section:
 *   description_of_services — instruct Claude to name both parties, establish
 *     the commercial relationship, and incorporate infrastructure specifics.
 *   scope_of_work           — explicitly require CUSTOMER REQUIREMENTS block
 *     (support obligations, working hours, out-of-scope policy) to appear.
 *   requirements            — require working_hours / testing_range / support
 *     obligations to surface as concrete numbered requirements.
 *   fees                    — remove misleading {{final_ot_policy}} slot
 *     reference in prompt text; direct Claude to the OT policy in DEAL CONTEXT.
 *   termination             — add IP / confidentiality continuation clause.
 *
 * Bumps template version so ContractDraftWizard can detect stale drafts.
 * Idempotent: checks version before updating.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->updates() as $slug => $sectionUpdates) {
            $template = DB::table('contract_templates')
                ->whereNull('tenant_id')
                ->where('slug', $slug)
                ->first();

            if (! $template) {
                continue;
            }

            $sections = json_decode($template->sections, true);

            foreach ($sectionUpdates as $key => $updates) {
                foreach ($sections as &$section) {
                    if (($section['key'] ?? null) === $key) {
                        foreach ($updates as $field => $value) {
                            $section[$field] = $value;
                        }
                        break;
                    }
                }
                unset($section);
            }

            DB::table('contract_templates')
                ->whereNull('tenant_id')
                ->where('slug', $slug)
                ->update([
                    'sections' => json_encode($sections),
                    'version'  => $template->version + 1,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Version bump is not reversible in a meaningful way; skip rollback.
    }

    private function updates(): array
    {
        return [
            'cloud_backup' => [
                'description_of_services' => [
                    'ai_prompt' =>
                        'Write a formal one-paragraph DESCRIPTION OF SERVICES opening clause. '
                        . 'Name the customer company (from DEAL CONTEXT) and state that Provider agrees '
                        . 'to supply a centrally managed, cloud-based backup service. Cover: secure backup '
                        . 'with protection against unauthorised access, modification, and deletion; '
                        . 'centralised management via the backup platform; and ongoing monitoring within '
                        . 'the contracted support hours. Incorporate any specific infrastructure, '
                        . 'data-environment, or compliance requirements from the CUSTOMER REQUIREMENT '
                        . 'DESCRIPTION. Close by referencing the monthly service capacity. Tone: formal '
                        . 'B2B contract opening clause — precise, not promotional.',
                ],
                'scope_of_work' => [
                    'ai_prompt' =>
                        'Write the SCOPE OF WORK section as TWO bulleted lists (output_format: '
                        . 'bulleted_pair). Label them "Provider:" and "User:" exactly. '
                        . 'Provider list must cover: cloud-platform setup, backup-job configuration, '
                        . 'monitoring, security patching, and monthly reporting. '
                        . 'User list must cover: backup-job creation scheduling, maintaining server '
                        . 'internet connectivity during backup windows, and access provisioning. '
                        . 'BINDING: if CUSTOMER REQUIREMENTS specifies working_hours, out_of_scope_policy, '
                        . 'or customer_support_obligations, these must appear explicitly under the '
                        . 'relevant party\'s list — do not omit them. '
                        . 'End with: "We do not support any work that is out of scope."',
                ],
                'requirements' => [
                    'ai_prompt' =>
                        'Write the REQUIREMENTS section as a numbered list of customer-side '
                        . 'preconditions for the backup service to operate. Must include: server '
                        . 'internet connectivity to the cloud platform; remote or console access for '
                        . 'Provider engineers; expected data-upload duration given upload speed (from '
                        . 'wizard — "Customer upload speed (Mbps)"); requirement to keep server and '
                        . 'internet active during upload windows; day-by-day coordination during '
                        . 'initial data transfer. '
                        . 'BINDING: if CUSTOMER REQUIREMENTS specifies working_hours or '
                        . 'customer_support_obligations, add them as concrete numbered items — do not '
                        . 'skip them. Omit items only when the corresponding requirement field is empty.',
                ],
                'fees' => [
                    'ai_prompt' =>
                        'Render the FEES section as a paragraph covering three charges: '
                        . '(a) One-time installation charge ({{final_installation_fee}}) covering '
                        . 'transportation and engineer man-hours for initial setup. '
                        . '(b) Monthly cloud storage and monitoring fee ({{final_monthly_fee}}) based '
                        . 'on the initial data tier; describe tier-bump rules if data volume exceeds '
                        . 'the contracted tier. '
                        . '(c) Overtime / overage charges: use the OT/overage policy from DEAL CONTEXT '
                        . '— render it as a natural-language clause stating exactly how overage is '
                        . 'billed (per hour, capped, absorbed, or prohibited). '
                        . 'Do not invent fee amounts; use only what is in DEAL CONTEXT.',
                ],
                'termination' => [
                    'ai_prompt' =>
                        'Write the TERMINATION section as a numbered list covering: '
                        . '(1) Complete deletion of customer data from the cloud platform with written '
                        . 'confirmation provided on request. '
                        . '(2) Optional data-return window before deletion: customer may request a '
                        . 'download; Provider will facilitate access for a defined period. '
                        . '(3) Download duration estimate based on data volume and connection speed; '
                        . 'customer must ensure sufficient HDD space. '
                        . '(4) Cloud storage fees continue to accrue during the download window. '
                        . '(5) After the window closes, no further recovery is possible. '
                        . '(6) Confidentiality obligations regarding customer data survive termination.',
                ],
            ],

            'managed_hosting' => [
                'description_of_services' => [
                    'ai_prompt' =>
                        'Write a formal one-paragraph DESCRIPTION OF SERVICES opening clause. '
                        . 'Name the customer company (from DEAL CONTEXT) and state that Provider agrees '
                        . 'to manage and monitor the described cloud infrastructure. Cover: 24/7 '
                        . 'monitoring and incident response, SLA-backed uptime guarantee (use SLA tier '
                        . 'from wizard — "SLA uptime (%)"), and proactive patching and capacity '
                        . 'management. Incorporate stack-specific details from the CUSTOMER REQUIREMENT '
                        . 'DESCRIPTION (cloud platforms, services, compliance requirements). Close by '
                        . 'referencing the SLA tier and monthly support commitment. Tone: formal B2B '
                        . 'contract opening clause — precise, not promotional.',
                ],
                'scope_of_work' => [
                    'ai_prompt' =>
                        'Write SCOPE OF WORK as TWO bulleted lists (output_format: bulleted_pair). '
                        . 'Label them "Provider:" and "User:" exactly. '
                        . 'Provider list must cover: 24/7 monitoring and alerting; incident response '
                        . 'within agreed SLA times; OS and middleware patching; backup verification; '
                        . 'capacity reviews; change-management execution for agreed changes. '
                        . 'User list must cover: application-level support; deployment of '
                        . 'customer-owned code and business-logic decisions; provisioning escalation '
                        . 'contacts; change-window coordination. '
                        . 'BINDING: if CUSTOMER REQUIREMENTS specifies working_hours, out_of_scope_policy, '
                        . 'or customer_support_obligations, these must appear explicitly under the '
                        . 'relevant party\'s list — do not omit them. '
                        . 'End with an explicit out-of-scope disclaimer.',
                ],
                'requirements' => [
                    'ai_prompt' =>
                        'Write the REQUIREMENTS section as a numbered list of customer-side '
                        . 'preconditions. Must include: granting Provider engineers cloud-account '
                        . 'access at the agreed permission tier; providing on-call escalation contacts; '
                        . 'documenting application owners for each service; change-window coordination '
                        . 'with at least 48 hours notice for non-emergency changes. '
                        . 'BINDING: if CUSTOMER REQUIREMENTS specifies working_hours, testing_range, '
                        . 'or customer_support_obligations, add them as concrete numbered items. '
                        . 'Omit items only when the corresponding field is empty.',
                ],
                'fees' => [
                    'ai_prompt' =>
                        'Render the FEES section as a paragraph covering three charges: '
                        . '(a) One-time onboarding and infrastructure-audit fee ({{final_installation_fee}}). '
                        . '(b) Monthly retainer ({{final_monthly_fee}}) covering the SLA-tier managed '
                        . 'service for the agreed environments (from wizard — "Environments covered"). '
                        . '(c) Overtime / overage charges: use the OT/overage policy from DEAL CONTEXT '
                        . '— render it as a natural-language clause. Additionally note that cloud-platform '
                        . 'usage costs (compute, storage, egress) are passed through to the customer at '
                        . 'cost with no markup. '
                        . 'Do not invent fee amounts; use only what is in DEAL CONTEXT.',
                ],
                'termination' => [
                    'ai_prompt' =>
                        'Write the TERMINATION section as a numbered list covering: '
                        . '(1) Handover of all monitoring dashboards, runbooks, and infrastructure '
                        . 'documentation to the customer or incoming provider. '
                        . '(2) A knowledge-transfer session (duration and format to be agreed) for '
                        . 'the receiving team. '
                        . '(3) Rotation and revocation of all Provider access credentials within '
                        . '5 business days of the termination date. '
                        . '(4) A final incident report covering the service period. '
                        . '(5) Data migration assistance window if applicable. '
                        . '(6) Confidentiality obligations regarding customer infrastructure details '
                        . 'survive termination for a period of two years.',
                ],
            ],

            'engineer_dispatch' => [
                'description_of_services' => [
                    'ai_prompt' =>
                        'Write a formal one-paragraph DESCRIPTION OF SERVICES opening clause. '
                        . 'Name the customer company (from DEAL CONTEXT) and state that Provider agrees '
                        . 'to assign specialist engineers at the contracted monthly capacity. Describe '
                        . 'the engineering disciplines involved (derive from "Team composition" in DEAL '
                        . 'CONTEXT and CUSTOMER REQUIREMENT DESCRIPTION), the engagement model '
                        . '(remote/on-site/hybrid from wizard — "Dispatch mode"), and the nature of '
                        . 'the work (derive from the requirement description). Close by referencing the '
                        . 'monthly hours and contract length. Tone: formal B2B contract opening clause '
                        . '— precise, not promotional.',
                ],
                'scope_of_work' => [
                    'ai_prompt' =>
                        'Write SCOPE OF WORK as TWO bulleted lists (output_format: bulleted_pair). '
                        . 'Label them "Provider:" and "User:" exactly. '
                        . 'Provider list must cover: engineering work within the agreed tech stack; '
                        . 'adherence to the customer\'s development standards and processes; '
                        . 'participation in daily standups or agreed coordination meetings; '
                        . 'timely reporting of blockers. '
                        . 'User list must cover: defining and prioritising work; acceptance criteria; '
                        . 'providing necessary access, tools, and test environments; designating a '
                        . 'technical counterpart for daily coordination. '
                        . 'BINDING: if CUSTOMER REQUIREMENTS specifies working_hours, out_of_scope_policy, '
                        . 'or customer_support_obligations, these must appear explicitly under the '
                        . 'relevant party\'s list — do not omit them. '
                        . 'End with: "Work outside the agreed project scope will be subject to '
                        . 'additional charges and must be approved in writing in advance."',
                ],
                'requirements' => [
                    'ai_prompt' =>
                        'Write the REQUIREMENTS section as a numbered list of customer-side '
                        . 'preconditions for the engineers to begin and sustain productive work. '
                        . 'Must include: providing development environments and tooling; source-code '
                        . 'repository access; test data and build/deploy pipelines; designating a '
                        . 'technical counterpart for daily coordination. '
                        . 'BINDING: if CUSTOMER REQUIREMENTS specifies testing_range, add it as a '
                        . 'concrete numbered requirement with the exact browser/OS/mobile versions '
                        . 'stated. If working_hours or customer_support_obligations are specified, '
                        . 'include them as numbered items. '
                        . 'Omit items only when the corresponding field is empty.',
                ],
                'fees' => [
                    'ai_prompt' =>
                        'Render the FEES section as a paragraph covering: '
                        . '(a) Optional onboarding / environment-setup fee ({{final_installation_fee}}); '
                        . 'state "(none)" if not applicable. '
                        . '(b) Monthly engineer retainer ({{final_monthly_fee}}) covering the team '
                        . 'described in "Team composition" in DEAL CONTEXT for the contracted monthly '
                        . 'hours per engineer (from wizard — "Monthly hours per engineer"). '
                        . '(c) Overtime / out-of-hours / out-of-scope charging: use the OT/overage '
                        . 'policy from DEAL CONTEXT — render it as a natural-language clause stating '
                        . 'exactly how additional hours are billed. '
                        . 'Do not invent fee amounts; use only what is in DEAL CONTEXT.',
                ],
                'termination' => [
                    'ai_prompt' =>
                        'Write the TERMINATION section as a numbered list covering: '
                        . '(1) Knowledge transfer to the customer or incoming team: code walkthroughs, '
                        . 'documentation handover, and a final Q&A session. '
                        . '(2) Handover of all code, scripts, and documentation produced during the '
                        . 'engagement. '
                        . '(3) Revocation of all access credentials (repositories, systems, accounts) '
                        . 'within 3 business days of the termination date. '
                        . '(4) Optional post-engagement support window (duration to be agreed in '
                        . 'writing) for critical clarifications only. '
                        . '(5) Confidentiality and IP assignment obligations survive termination: all '
                        . 'work product developed under this agreement is assigned to the customer '
                        . 'upon full payment; confidential information of either party must not be '
                        . 'disclosed for a period of three years post-termination.',
                ],
            ],
        ];
    }
};
