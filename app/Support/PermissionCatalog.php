<?php

namespace App\Support;

/**
 * Single source of truth for every permission string the app recognises.
 *
 * Permission strings are wired into:
 *   - routes/api.php           — as `permission:<key>` middleware
 *   - PermissionGuard wrappers — in the frontend
 *   - lib/route-permissions.ts — sidebar visibility on the frontend
 *
 * A new permission key only does something once a developer wires it
 * somewhere. Tenant admins compose existing keys into roles; they
 * cannot invent new keys (intentional — see CLAUDE.md RBAC plan).
 *
 * Groups are display-only — they let the admin UI render checkboxes
 * organised by module instead of one long list.
 */
class PermissionCatalog
{
    /**
     * @return array<int, array{key: string, label: string, group: string, description: ?string}>
     */
    public static function all(): array
    {
        return [
            // CRM
            ['key' => 'view_crm',           'group' => 'CRM',           'label' => 'View CRM',                'description' => 'See the sales pipeline and deal list.'],
            ['key' => 'manage_crm',         'group' => 'CRM',           'label' => 'Manage CRM',              'description' => 'Create, edit, win, lose, or delete deals.'],

            // Estimation
            ['key' => 'manage_estimation',  'group' => 'Estimation',    'label' => 'Manage Estimation',       'description' => 'Edit estimation rows, ghost roles, and overheads on deals.'],

            // Contracts
            ['key' => 'view_contracts',     'group' => 'Contracts',     'label' => 'View Contracts',          'description' => 'See the contracts list and contract detail pages.'],

            // Projects
            ['key' => 'view_projects',      'group' => 'Projects',      'label' => 'View Projects',           'description' => 'See the project list and project delivery pages.'],
            ['key' => 'manage_projects',    'group' => 'Projects',      'label' => 'Manage Projects',         'description' => 'Edit project metadata, kickoff dates, and team assignments.'],
            ['key' => 'view_schedule_tracking', 'group' => 'Projects',  'label' => 'View Schedule Tracking',  'description' => 'See per-phase progress vs plan and the schedule-tracking dashboards.'],
            ['key' => 'track_time',         'group' => 'Projects',      'label' => 'Track Time',              'description' => 'Log time entries against active projects.'],
            ['key' => 'approve_time',       'group' => 'Projects',      'label' => 'Approve Time',            'description' => 'Approve, reject, or unlock submitted time entries.'],
            ['key' => 'log_progress',       'group' => 'Projects',      'label' => 'Log Phase Progress',      'description' => 'Record daily phase progress against assigned work.'],

            // Organization
            ['key' => 'manage_organization','group' => 'Organization',  'label' => 'Manage Organization',     'description' => 'Edit departments, capacity roles, and global overheads.'],
            ['key' => 'view_employees',     'group' => 'Organization',  'label' => 'View Employees',          'description' => 'See the employee list and salary information.'],
            ['key' => 'manage_employees',   'group' => 'Organization',  'label' => 'Manage Employees',        'description' => 'Add, edit, or remove employees.'],

            // Dashboard / Reports
            ['key' => 'view_dashboard',     'group' => 'Reporting',     'label' => 'View Dashboard',          'description' => 'Access the main dashboard overview.'],
            ['key' => 'view_reports',       'group' => 'Reporting',     'label' => 'View Reports',            'description' => 'Access financial P&L and forecast pages.'],

            // Tenant settings
            ['key' => 'manage_tenant',      'group' => 'Tenant',        'label' => 'Manage Tenant Settings',  'description' => 'Edit tenant-wide settings, currency, and exchange rates. Required to edit roles and permissions.'],
        ];
    }

    /**
     * Flat list of valid permission keys.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_map(fn ($p) => $p['key'], self::all());
    }

    public static function isValidKey(string $key): bool
    {
        return $key === 'all' || in_array($key, self::keys(), true);
    }
}
