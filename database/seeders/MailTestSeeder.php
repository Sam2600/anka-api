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
 * Seeds a fully-prepared deal + contract draft so the contract email flow
 * can be tested in one click.
 *
 *   php artisan db:seed --class=MailTestSeeder
 *
 * The deal lands at rank A (negotiation) with every Estimation handoff
 * field populated, contact_email = naingaunglinn369@gmail.com (the only
 * Mailgun sandbox authorized recipient on this account), and a contract
 * draft in 'draft' status with all sections pre-rendered and with no
 * unresolved-placeholder markers, so the send guard passes.
 *
 * Re-runnable — wipes the previous MAIL TEST deal + its drafts each run,
 * so you always get a clean state.
 */
class MailTestSeeder extends Seeder
{
    private const DEAL_NAME = 'MAIL TEST — Cloud Backup';
    private const RECIPIENT_EMAIL = 'naingaunglinn369@gmail.com';
    private const DIVIDER = '────────────────────────────────────────────────';

    public function run(): void
    {
        Model::unguarded(function () {
            $tenant = Tenant::first();
            if (! $tenant) {
                $this->command->error('No tenant found — run the main DatabaseSeeder first.');
                return;
            }

            // Bind tenant for any BelongsToTenant auto-injection elsewhere.
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

            // Wipe any prior MAIL TEST deal + its drafts so re-runs are clean.
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
                'client' => 'Test Customer Co., Ltd.',
                'contact_name' => 'Naing Aung Linn',
                'contact_email' => self::RECIPIENT_EMAIL,
                'contact_phone' => '+95 9 123 456 789',
                'status' => 'negotiation',
                'lifecycle_status' => 'active',
                'win_probability' => 80,
                'lead_source' => 'inbound',
                'expected_close_date' => now()->addDays(14)->toDateString(),
                'client_budget' => 60000000,
                'timeline_months' => 12,
                'workload_hours' => 1200,
                'workload_description' =>
                    "Centralized cloud backup for the customer's on-prem servers. Veritas Backup "
                    ."Exec 22.2 backing up to AWS S3 with 10 TB initial data, 30-day retention. "
                    ."Internet upload speed ~50 Mbps. Working hours 09:00–16:00 MST.",
                'target_margin' => 35.0,

                // OT / overage policy
                'ot_policy_model' => 'capped_then_customer_pays',
                'ot_rate_per_hour' => 25000,
                'ot_included_hours_per_month' => 8,
                'ot_notes' => 'First 8 hrs/month included; beyond billed at 25,000 MMK/hr.',

                // Customer requirements
                'customer_support_obligations' =>
                    'Customer maintains internet connectivity to AWS endpoints and grants '
                    .'console access to the backup server during transfer windows.',
                'out_of_scope_policy' =>
                    'Backup of personal devices, non-listed servers, and application-level '
                    .'data migration are out of scope and billed separately.',
                'working_hours' => '09:00 AM – 04:00 PM MST, Mon–Fri except public holidays.',
                'testing_range' =>
                    'Backup-restore validation each calendar quarter on a sample dataset '
                    .'agreed in writing.',

                // Estimation handoff (everything contract drafting needs)
                'final_monthly_fee' => 5000000,
                'final_installation_fee' => 1500000,
                'final_contract_months' => 12,
                'final_ot_policy' => 'capped_then_customer_pays',
                'final_support_hours_per_month' => 12,
                'final_team_summary' => '1 senior DevOps engineer (50% allocation), 1 backup specialist (25%).',
                'final_currency' => 'MMK',
                'final_confirmed_at' => now()->subDays(2),
                'suggested_template_variant' => 'cloud_backup',

                'base_labor_cost' => 38000000,
                'overhead_cost' => 4500000,
                'buffer_cost' => 2500000,
                'total_estimated_cost' => 45000000,
                'estimated_gross_profit' => 15000000,
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
                // Mirror sections[].rendered into ai_outputs for AI types so
                // the audit trail isn't empty. Slight white-lie for ai_with_slots
                // (real Claude output wouldn't have slots already filled) but
                // fine for seeded data.
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
            $this->command->info(' MAIL TEST data seeded.');
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
            $this->command->info(' Recipient (must be authorized in Mailgun sandbox):');
            $this->command->info('   '.self::RECIPIENT_EMAIL);
            $this->command->info(self::DIVIDER);
        });
    }

    /**
     * Realistic wizard inputs across the cloud_backup template's questions.
     */
    private function wizardInputs(): array
    {
        return [
            'commencement_date' => now()->addDays(7)->toDateString(),
            'trial_months' => 1,
            'support_window_start' => '09:00 AM',
            'support_window_end' => '04:00 PM',
            'payment_terms_days' => 7,
            'backup_software' => 'Veritas Backup Exec Version 22.2',
            'cloud_platform' => 'AWS',
            'data_tier_tb' => 10,
            'upload_speed_mbps' => 50,
        ];
    }

    /**
     * Pre-rendered sections — bypasses Claude so the seeder runs offline.
     * Each section's `rendered` field is the text the PDF / email will
     * contain. No unresolved placeholder markers, so the markSent guard
     * passes on the first click.
     */
    private function prerenderedSections(): array
    {
        $commencement = now()->addDays(7)->toDateString();
        $endDate = now()->addDays(7)->addMonths(12)->toDateString();

        return [
            [
                'key' => 'description_of_services',
                'title' => 'Description of Services',
                'type' => 'ai_written',
                'output_format' => 'paragraph',
                'rendered' =>
                    'Brycen Myanmar Ltd. ("Provider") shall provide centralized cloud backup '
                    .'services to Test Customer Co., Ltd. ("User"), comprising secure off-site '
                    .'storage of the User\'s server data with protection against unauthorized '
                    .'modification and deletion. The service includes initial setup, ongoing '
                    .'monitoring, and quarterly restore validation as detailed in the sections '
                    .'that follow.',
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
                    ."| Cloud platform | AWS (Asia Pacific — Singapore region) |\n"
                    ."| Initial data tier | 10 TB |\n"
                    ."| Retention policy | 30 days rolling, with quarterly cold-storage snapshots |\n",
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
                    ."- Initial: configure AWS S3 buckets, install Veritas agents, encrypt data in transit and at rest.\n"
                    ."- Initial: validate first full backup end-to-end before sign-off.\n"
                    ."- Monthly: monitor backup health, alert on failures within 1 business day.\n"
                    ."- Monthly: rotate access keys quarterly; security patch backup agents.\n"
                    ."\n"
                    ."User:\n"
                    ."- Initial: provide server list, console access, and AWS account billing details.\n"
                    ."- Initial: confirm acceptable backup windows and bandwidth caps.\n"
                    ."- Monthly: create or adjust backup jobs as data sources change.\n"
                    ."- Monthly: schedule restore tests with Provider during the agreed window.\n"
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
                    "- Server-side internet connectivity to the AWS Asia Pacific region with at least 50 Mbps sustained upload.\n"
                    ."- Remote console access to source servers during initial transfer and quarterly restore tests.\n"
                    ."- Initial 10 TB full backup expected to complete in approximately 18–22 days at 50 Mbps; "
                    ."the source server and internet must remain active throughout.\n"
                    ."- Daily coordination between User's IT contact and Provider during the transfer window.\n"
                    ."- User is responsible for backup-job retention scheduling beyond the default 30-day window.",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'fees',
                'title' => 'Calculation of Fees and Other Charges',
                'type' => 'ai_with_slots',
                'output_format' => 'paragraph',
                'rendered' =>
                    "Monthly Service Fee: MMK 5,000,000 per month, invoiced at the end of each calendar month.\n"
                    ."Initial Setup Fee: MMK 1,500,000, invoiced once on the Commencement Date.\n\n"
                    ."Overtime: the first 8 hours of additional support per month are included. Beyond 8 hours, "
                    ."additional support is billed at MMK 25,000 per hour. Charges shall be reflected on the "
                    ."following month's invoice.\n\n"
                    ."Out-of-scope work is quoted separately on receipt of a written request.",
                'has_todo' => false,
                'user_edited' => false,
            ],
            [
                'key' => 'usage_period',
                'title' => 'Usage Period',
                'type' => 'slot_only',
                'output_format' => 'paragraph',
                'rendered' =>
                    "The term of this Agreement shall be 12 months starting from the Commencement Date {$commencement}.\n\n"
                    ."The first month following the Commencement Date is offered free of charge as a trial period; "
                    ."Monthly Service Fees begin from the second month.\n\n"
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
                    "(a) Total service supporting hours = 12 hours/month.\n"
                    ."(b) Online support for technical issues during 09:00 AM to 04:00 PM (Myanmar Standard Time, UTC+6:30) except Holidays.\n"
                    ."(c) If issues are reported after 04:00 PM, support will continue the next working day.\n"
                    ."(d) Support and monitoring are limited to the cloud backup service described above.\n"
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
                    ."(b) Payment is payable within 7 days of the date of invoice.\n"
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
