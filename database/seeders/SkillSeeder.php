<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a curated baseline skill catalog for every tenant.
 *
 * Skills feed the AI Team Builder — without them, Claude has no signal to
 * match employees to project requirements. The catalog is intentionally
 * agency-flavored (web/mobile/design/PM/QA) and uses the same six categories
 * the SkillForm exposes in the UI.
 *
 * Idempotent: relies on the (tenant_id, name) unique index via insertOrIgnore,
 * so re-running only fills in skills that don't yet exist for each tenant.
 */
class SkillSeeder extends Seeder
{
    /** @var array<int, array{name: string, category: string}> */
    private const CATALOG = [
        // Technical — frontend
        ['name' => 'React',           'category' => 'Technical'],
        ['name' => 'Next.js',         'category' => 'Technical'],
        ['name' => 'Vue.js',          'category' => 'Technical'],
        ['name' => 'Angular',         'category' => 'Technical'],
        ['name' => 'TypeScript',      'category' => 'Technical'],
        ['name' => 'Tailwind CSS',    'category' => 'Technical'],

        // Technical — backend
        ['name' => 'Laravel',         'category' => 'Technical'],
        ['name' => 'Node.js',         'category' => 'Technical'],
        ['name' => 'Python',          'category' => 'Technical'],
        ['name' => 'Django',          'category' => 'Technical'],
        ['name' => 'FastAPI',         'category' => 'Technical'],
        ['name' => 'GraphQL',         'category' => 'Technical'],
        ['name' => 'REST API',        'category' => 'Technical'],

        // Technical — data & infra
        ['name' => 'PostgreSQL',      'category' => 'Technical'],
        ['name' => 'MongoDB',         'category' => 'Technical'],
        ['name' => 'Redis',           'category' => 'Technical'],
        ['name' => 'Docker',          'category' => 'Technical'],
        ['name' => 'Kubernetes',      'category' => 'Technical'],
        ['name' => 'AWS',             'category' => 'Technical'],

        // Technical — mobile & emerging
        ['name' => 'iOS',             'category' => 'Technical'],
        ['name' => 'Android',         'category' => 'Technical'],
        ['name' => 'React Native',    'category' => 'Technical'],
        ['name' => 'Machine Learning','category' => 'Technical'],

        // Creative
        ['name' => 'UI/UX Design',    'category' => 'Creative'],
        ['name' => 'Figma',           'category' => 'Creative'],
        ['name' => 'Brand Identity',  'category' => 'Creative'],
        ['name' => 'Motion Design',   'category' => 'Creative'],

        // Management
        ['name' => 'Scrum',           'category' => 'Management'],
        ['name' => 'Agile',           'category' => 'Management'],
        ['name' => 'Stakeholder Mgmt','category' => 'Management'],
        ['name' => 'Roadmapping',     'category' => 'Management'],

        // Operations (covers QA + delivery ops)
        ['name' => 'Manual Testing',    'category' => 'Operations'],
        ['name' => 'Automated Testing', 'category' => 'Operations'],
        ['name' => 'CI/CD',             'category' => 'Operations'],
        ['name' => 'Release Management','category' => 'Operations'],

        // Financial
        ['name' => 'Project Budgeting', 'category' => 'Financial'],
        ['name' => 'Financial Modeling','category' => 'Financial'],

        // Legal
        ['name' => 'Contract Review',   'category' => 'Legal'],
    ];

    public function run(): void
    {
        $now = now()->toDateTimeString();
        $tenantIds = DB::table('tenants')->pluck('id');

        if ($tenantIds->isEmpty()) {
            $this->command?->warn('SkillSeeder: no tenants found — nothing to seed.');
            return;
        }

        $totalInserted = 0;

        foreach ($tenantIds as $tenantId) {
            $rows = [];
            foreach (self::CATALOG as $skill) {
                $rows[] = [
                    'id'         => (string) Str::uuid(),
                    'tenant_id'  => $tenantId,
                    'name'       => $skill['name'],
                    'category'   => $skill['category'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // insertOrIgnore relies on the unique (tenant_id, name) index — already
            // existing rows are silently skipped, making re-runs cheap and safe.
            $beforeCount = DB::table('skills')->where('tenant_id', $tenantId)->count();
            DB::table('skills')->insertOrIgnore($rows);
            $afterCount  = DB::table('skills')->where('tenant_id', $tenantId)->count();

            $inserted = $afterCount - $beforeCount;
            $totalInserted += $inserted;

            $this->command?->info(
                sprintf('SkillSeeder: tenant %s — inserted %d skill(s) (now %d total).',
                    $tenantId, $inserted, $afterCount)
            );
        }

        $this->command?->info("SkillSeeder: done — {$totalInserted} skill(s) inserted across {$tenantIds->count()} tenant(s).");
    }
}
