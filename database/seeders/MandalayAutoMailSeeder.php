<?php

namespace Database\Seeders;

use App\Models\ContractTemplate;
use App\Models\Deal;
use App\Models\DealContractDraft;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Second mail-flow test deal — Mandalay Auto Parts (Azure cloud backup,
 * 24-month contract, 5 TB initial data). Recipient is the second
 * Mailgun sandbox authorized email: aungkyawpaing65@gmail.com.
 *
 *   php artisan db:seed --class=MandalayAutoMailSeeder
 *
 * Different business scenario from MailTestSeeder so the email + PDF
 * read like a separate deal end-to-end. Re-runnable — wipes the prior
 * Mandalay deal + drafts each run.
 */
class MandalayAutoMailSeeder extends Seeder
{
    private const DEAL_NAME = 'Mandalay Auto Parts — Cloud Backup';
    private const RECIPIENT_EMAIL = 'aungkyawpaing65@gmail.com';
    private const DIVIDER = '────────────────────────────────────────────────';

    public function run(): void
    {
        Model::unguarded(function () {
            $tenant = Tenant::first();
            if (! $tenant) {
                $this->command->error('No tenant found — run the main DatabaseSeeder first.');
                return;
            }

            app()->instance('tenant_id', $tenant->id);

            $user = User::where('tenant_id', $tenant->id)
                ->where('is_super_admin', false)
                ->first();
            if (! $user) {
                $this->command->error('No tenant user found.');
                return;
            }

            $template = ContractTemplate::where('slug', 'cloud_backup')
                ->whereNull('tenant_id')
                ->first();
            if (! $template) {
                $this->command->error('cloud_backup contract template missing — run the template seed migration.');
                return;
            }

            // Wipe any prior run so we get a clean state.
            $existing = Deal::where('tenant_id', $tenant->id)
                ->where('name', self::DEAL_NAME)
                ->get();
            foreach ($existing as $old) {
                DealContractDraft::where('deal_id', $old->id)->forceDelete();
                $old->forceDelete();
            }

            $deal = Deal::create([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => $tenant->id,
                'name' => self::DEAL_NAME,
                'client' => 'Mandalay Auto Parts Distribution Co., Ltd.',
                'contact_name' => 'U Aung Kyaw Paing',
                'contact_email' => self::RECIPIENT_EMAIL,
                'contact_phone' => '+95 9 7765 4321',
                'status' => 'negotiation',
                'lifecycle_status' => 'active',
                'win_probability' => 85,
                'lead_source' => 'referral',
                'expected_close_date' => now()->addDays(10)->toDateString(),
                'client_budget' => 200000000,
                'timeline_months' => 24,
                'workload_hours' => 2400,
                'workload_description' =>
                    "Off-site backup for Mandalay Auto Parts' ERP, inventory, and finance systems "
                    ."hosted across three on-prem Windows Server 2019 nodes. Total source data "
                    ."approximately 5 TB; growing at ~150 GB/month. Veritas Backup Exec 22.2 "
                    ."backing up to Microsoft Azure (Southeast Asia region) with 60-day retention "
                    ."and quarterly cold-storage snapshots. Site upload bandwidth 30 Mbps "
                    ."dedicated. Business hours 08:30–17:30 MST; backups must run outside that window.",
                'target_margin' => 32.0,

                // OT — straight per-hour billing, no included cap.
                'ot_policy_model' => 'customer_pays_per_hour',
                'ot_rate_per_hour' => 35000,
                'ot_included_hours_per_month' => 0,
                'ot_notes' => 'No included support hours. All ad-hoc support billed at MMK 35,000/hr.',

                'customer_support_obligations' =>
                    'Customer maintains site VPN to Azure endpoints, provides domain admin '
                    .'credentials for the three backup-source servers, and notifies Provider '
                    .'48 hours before any planned ERP downtime.',
                'out_of_scope_policy' =>
                    'Application-level data migration, ERP version upgrades, and recovery '
                    .'of files deleted prior to the Commencement Date are out of scope and '
                    .'require a separate written engagement.',
                'working_hours' =>
                    'Backups scheduled outside 08:30 AM – 05:30 PM MST on business days. '
                    .'Restore tests by appointment.',
                'testing_range' =>
                    'Quarterly restore validation on a 50 GB representative sample from each '
                    .'source server. Annual full-restore drill on a designated test environment '
                    .'supplied by Customer.',

                // Estimation handoff
                'final_monthly_fee' => 7500000,
                'final_installation_fee' => 2000000,
                'final_contract_months' => 24,
                'final_ot_policy' => 'customer_pays_per_hour',
                'final_support_hours_per_month' => 8,
                'final_team_summary' =>
                    '1 senior DevOps engineer (60% allocation), 1 backup specialist (30%), '
                    .'1 account manager (10%).',
                'final_currency' => 'MMK',
                'final_confirmed_at' => now()->subDay(),
                'suggested_template_variant' => 'cloud_backup',

                'base_labor_cost' => 130000000,
                'overhead_cost' => 12000000,
                'buffer_cost' => 8000000,
                'total_estimated_cost' => 150000000,
                'estimated_gross_profit' => 50000000,
            ]);

            $sections = $this->prerenderedSections();

            $draft = DealContractDraft::create([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'template_id' => $template->id,
                'template_version_at_generation' => $template->version,
                'status' => DealContractDraft::STATUS_DRAFT,
                'version' => 1,
                'wizard_inputs' => $this->wizardInputs(),
                'ai_outputs' => collect($sections)
                    ->filter(fn ($s) => in_array($s['type'] ?? '', ['ai_written', 'ai_with_slots'], true))
                    ->mapWithKeys(fn ($s) => [$s['key'] => $s['rendered']])
                    ->all(),
                'sections' => $sections,
                'generated_by_user_id' => $user->id,
            ]);

            $frontend = env('FRONTEND_URL', 'http://localhost:3000');

            $this->command->info('');
            $this->command->info(self::DIVIDER);
            $this->command->info(' Mandalay Auto Parts MAIL TEST seeded.');
            $this->command->info(self::DIVIDER);
            $this->command->info(" Tenant:    {$tenant->name} ({$tenant->id})");
            $this->command->info(" Sign in as: {$user->email}");
            $this->command->info('');
            $this->command->info(' Deal detail:');
            $this->command->info("   {$frontend}/project-pipeline/{$deal->id}");
            $this->command->info('');
            $this->command->info(' Contract draft (wizard step 3 = Send):');
            $this->command->info("   {$frontend}/project-pipeline/{$deal->id}/contract-draft/{$draft->id}");
            $this->command->info('');
            $this->command->info(' Recipient (must be a verified Mailgun sandbox recipient):');
            $this->command->info('   '.self::RECIPIENT_EMAIL);
            $this->command->info(self::DIVIDER);
        });
    }

    private function wizardInputs(): array
    {
        return [
            'commencement_date' => now()->addDays(14)->toDateString(),
            'trial_months' => 0,
            'support_window_start' => '06:00 PM',
            'support_window_end' => '07:30 AM',
            'payment_terms_days' => 14,
            'backup_software' => 'Veritas Backup Exec Version 22.2',
            'cloud_platform' => 'Azure',
            'data_tier_tb' => 5,
            'upload_speed_mbps' => 30,
        ];
    }

    private function prerenderedSections(): array
    {
        $commencement = now()->addDays(14)->toDateString();
        $endDate = now()->addDays(14)->addMonths(24)->toDateString();

        return [
            [
                'key' => 'description_of_services',
                'title' => 'Description of Services',
                'type' => 'ai_written',
                'output_format' => 'paragraph',
                'rendered' =>
                    'Provider shall deliver an off-site backup service to Mandalay Auto Parts '
                    .'Distribution Co., Ltd. ("User") covering the User\'s ERP, inventory, and '
                    .'finance systems hosted on three Windows Server 2019 nodes. The service '
                    .'comprises centralized configuration of backup jobs to Microsoft Azure '
                    .'(Southeast Asia region), automated nightly transfers, and safeguards '
                    .'against unauthorized modification or deletion of the off-site copies.',
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'services_provided',
                'title' => 'Services Provided',
                'type' => 'ai_with_slots',
                'output_format' => 'table',
                'rendered' =>
                    "| Item | Value |\n"
                    ."|---|---|\n"
                    ."| Backup software | Veritas Backup Exec Version 22.2 |\n"
                    ."| Cloud platform | Microsoft Azure — Southeast Asia (Singapore) |\n"
                    ."| Initial data tier | 5 TB across three source servers |\n"
                    ."| Retention policy | 60 days rolling, plus quarterly cold-storage snapshots |\n"
                    ."| Backup schedule | Nightly incremental, weekly full |\n",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'scope_of_work',
                'title' => 'Scope of Work',
                'type' => 'ai_written',
                'output_format' => 'bulleted_pair',
                'rendered' =>
                    "Provider:\n"
                    ."- Initial: design and document the three-server backup topology, install Veritas agents, "
                    ."configure Azure containers with encryption at rest.\n"
                    ."- Initial: complete first full backup of all three servers and confirm restore integrity.\n"
                    ."- Monthly: monitor backup jobs daily; investigate and remediate failures within one business day.\n"
                    ."- Monthly: rotate Azure access keys and patch Veritas agents per vendor advisories.\n"
                    ."- Quarterly: execute restore validation on a 50 GB sample and deliver a written report.\n"
                    ."\n"
                    ."User:\n"
                    ."- Initial: provide domain admin credentials and VPN access to the three source servers.\n"
                    ."- Initial: confirm backup window (18:00–07:30 MST) does not conflict with ERP nightly jobs.\n"
                    ."- Monthly: notify Provider 48 hours before any planned server maintenance.\n"
                    ."- Monthly: review backup-health reports issued by Provider on the 5th of each month.\n"
                    ."- Annually: supply a test environment for the full-restore drill.\n"
                    ."\n"
                    ."We do not support any work that is out of scope.",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'requirements',
                'title' => 'Requirements',
                'type' => 'ai_written',
                'output_format' => 'bulleted_simple',
                'rendered' =>
                    "- Site VPN to Microsoft Azure (Southeast Asia region) sustaining at least 30 Mbps upload during backup windows.\n"
                    ."- Domain admin credentials for the three Windows Server 2019 source nodes; access scoped to backup operations only.\n"
                    ."- Initial 5 TB full backup expected to complete in approximately 17–20 days at 30 Mbps; "
                    ."source servers and VPN must remain active throughout this period.\n"
                    ."- Daily coordination during the initial transfer window between User's IT lead and Provider's engineer.\n"
                    ."- User to maintain Azure billing on a separate subscription owned by User; Provider configures but does not own the subscription.\n"
                    ."- Customer notifies Provider 48 hours before any planned ERP downtime to avoid false-positive backup alerts.",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'fees',
                'title' => 'Calculation of Fees and Other Charges',
                'type' => 'ai_with_slots',
                'output_format' => 'paragraph',
                'rendered' =>
                    "Monthly Service Fee: MMK 7,500,000 per month, invoiced at the end of each calendar month.\n"
                    ."Initial Setup Fee: MMK 2,000,000, invoiced once on the Commencement Date.\n\n"
                    ."Overtime: this agreement does not include any free support hours. All ad-hoc "
                    ."support requested by User is billed at MMK 35,000 per hour, reflected on the "
                    ."following month's invoice with an itemized log of hours.\n\n"
                    ."Out-of-scope work — including application-level data migration, ERP version "
                    ."upgrades, and recovery of files deleted prior to the Commencement Date — is "
                    ."quoted separately on receipt of a written request.",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'usage_period',
                'title' => 'Usage Period',
                'type' => 'slot_only',
                'output_format' => 'paragraph',
                'rendered' =>
                    "The term of this Agreement shall be 24 months starting from the Commencement Date {$commencement}.\n\n"
                    ."No trial period applies; Monthly Service Fees begin from the Commencement Date.\n\n"
                    ."The Actual Usage Period shall start from {$commencement} and expire on {$endDate}.",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'monitoring',
                'title' => 'Monitoring',
                'type' => 'slot_only',
                'output_format' => 'paragraph',
                'rendered' =>
                    "(a) Total service supporting hours = 8 hours/month (paid; see Fees section).\n"
                    ."(b) Online support for technical issues during 06:00 PM to 07:30 AM (Myanmar Standard Time, UTC+6:30), "
                    ."matching the customer's after-hours backup window. Holidays excepted.\n"
                    ."(c) If issues are reported after 07:30 AM, support continues the following backup window.\n"
                    ."(d) Support and monitoring are limited to the Azure cloud backup service described above.\n"
                    ."(e) We do not support any other options.",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'payment_policy',
                'title' => 'Payment Policy',
                'type' => 'fixed',
                'output_format' => 'paragraph',
                'rendered' =>
                    "(a) Provider will submit invoices to User at the end of each month.\n"
                    ."(b) Payment is payable within 14 days of the date of invoice.\n"
                    ."(c) All applicable bank charges and taxes shall be paid by the User.",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'cancellation_fee',
                'title' => 'Cancellation Fee',
                'type' => 'fixed',
                'output_format' => 'paragraph',
                'rendered' =>
                    '(a) User will notify Provider by official email at least one month before the break time. '
                    .'If User breaks this contract before the end of the Actual Usage Period, '
                    .'Provider may charge the remaining months as an Early Termination fee.',
                'has_todo' => false,
                'user_edited' => false,
            ],
        ];
    }
}
