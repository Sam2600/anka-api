<?php

namespace Database\Seeders;

/**
 * DEMO DATA MAP
 * -------------
 * Pre-assigned UUIDs and shared constants for all demo seeders.
 * Ensures cross-references stay consistent across every seeder file.
 *
 * Primary org   : Pixel Agency  (main demo tenant)
 * Secondary org : Nova Studio   (tenant-isolation proof)
 */
class DemoDataMap
{
    // ── Owner (Super Admin) ──────────────────────────────────────────────
    public const OWNER_ID = '00000001-0001-0001-0001-000000000001';

    public const OWNER_EMAIL = 'owner@anka.test';

    public const OWNER_PASSWORD_HASH = '$2y$12$1yRW1IEsHjM0B3g.XgRYuO/1BzB9.M7rO8zL6e1Q2A3B4C5D6E7F8'; // Demo@1234

    // ── Tenants ──────────────────────────────────────────────────────────
    public const PIXEL_TENANT_ID = '10000001-0001-0001-0001-000000000001';

    public const NOVA_TENANT_ID = '10000002-0002-0002-0002-000000000002';

    // ── Pixel Agency Users ───────────────────────────────────────────────
    public const PIXEL_ADMIN_USER_ID = '20000001-0001-0001-0001-000000000001';

    public const PIXEL_DEV_USER_ID = '20000001-0001-0001-0001-000000000002';

    public const PIXEL_DESIGNER_USER_ID = '20000001-0001-0001-0001-000000000003';

    public const PIXEL_PM_USER_ID = '20000001-0001-0001-0001-000000000004';

    public const PIXEL_QA_USER_ID = '20000001-0001-0001-0001-000000000005';

    // ── Nova Studio Users ────────────────────────────────────────────────
    public const NOVA_ADMIN_USER_ID = '30000001-0001-0001-0001-000000000001';

    public const NOVA_EMP1_USER_ID = '30000001-0001-0001-0001-000000000002';

    public const NOVA_EMP2_USER_ID = '30000001-0001-0001-0001-000000000003';

    // ── Pixel Agency Departments ─────────────────────────────────────────
    public const PIXEL_DEPT_ENG_ID = '40000001-0001-0001-0001-000000000001';

    public const PIXEL_DEPT_DESIGN_ID = '40000001-0001-0001-0001-000000000002';

    public const PIXEL_DEPT_PM_ID = '40000001-0001-0001-0001-000000000003';

    public const PIXEL_DEPT_QA_ID = '40000001-0001-0001-0001-000000000004';

    // ── Pixel Agency Roles (job roles) ───────────────────────────────────
    public const PIXEL_ROLE_DEV_ID = '50000001-0001-0001-0001-000000000001';

    public const PIXEL_ROLE_DESIGN_ID = '50000001-0001-0001-0001-000000000002';

    public const PIXEL_ROLE_PM_ID = '50000001-0001-0001-0001-000000000003';

    public const PIXEL_ROLE_QA_ID = '50000001-0001-0001-0001-000000000004';

    // ── Pixel Agency Capacity Roles ──────────────────────────────────────
    public const PIXEL_CAP_BACKEND_ID = '60000001-0001-0001-0001-000000000001';

    public const PIXEL_CAP_FRONTEND_ID = '60000001-0001-0001-0001-000000000002';

    public const PIXEL_CAP_PM_ID = '60000001-0001-0001-0001-000000000003';

    public const PIXEL_CAP_QA_ID = '60000001-0001-0001-0001-000000000004';

    public const PIXEL_CAP_DESIGN_ID = '60000001-0001-0001-0001-000000000005';

    // ── Pixel Agency Employees ───────────────────────────────────────────
    public const PIXEL_EMP_DEV_ID = '70000001-0001-0001-0001-000000000001';

    public const PIXEL_EMP_DESIGN_ID = '70000001-0001-0001-0001-000000000002';

    public const PIXEL_EMP_PM_ID = '70000001-0001-0001-0001-000000000003';

    public const PIXEL_EMP_QA_ID = '70000001-0001-0001-0001-000000000004';

    // ── Nova Studio Employees ────────────────────────────────────────────
    public const NOVA_EMP1_ID = '80000001-0001-0001-0001-000000000001';

    public const NOVA_EMP2_ID = '80000001-0001-0001-0001-000000000002';

    // ── Pixel Agency Deals ───────────────────────────────────────────────
    public const DEAL_HARTWELL_ID = '90000001-0001-0001-0001-000000000001'; // Lead

    public const DEAL_SUNRISE_ID = '90000001-0001-0001-0001-000000000002'; // Inquiry (Qualified)

    public const DEAL_BLUEPEAK_ID = '90000001-0001-0001-0001-000000000003'; // Proposal (AI demo)

    public const DEAL_MERIDIAN_ID = '90000001-0001-0001-0001-000000000004'; // Contract (Negotiation)

    public const DEAL_APEX_ID = '90000001-0001-0001-0001-000000000005'; // Won → active project

    public const DEAL_SUMMIT_ID = '90000001-0001-0001-0001-000000000006'; // Lost

    // ── Nova Studio Deals ────────────────────────────────────────────────
    public const NOVA_DEAL1_ID = '91000001-0001-0001-0001-000000000001';

    public const NOVA_DEAL2_ID = '91000001-0001-0001-0001-000000000002';

    // ── Contracts ────────────────────────────────────────────────────────
    public const CONTRACT_APEX_ID = 'a0000001-0001-0001-0001-000000000001'; // Completed

    public const CONTRACT_MERIDIAN_ID = 'a0000001-0001-0001-0001-000000000002'; // Draft

    public const CONTRACT_HARTWELL_ID = 'a0000001-0001-0001-0001-000000000003'; // Completed (no deal)

    public const CONTRACT_SUNRISE_ID = 'a0000001-0001-0001-0001-000000000004'; // Draft (no deal)

    public const CONTRACT_NOVA1_ID = 'a0000001-0001-0001-0001-000000000005'; // Nova Studio

    // ── Projects ─────────────────────────────────────────────────────────
    public const PROJECT_APEX_ID = 'b0000001-0001-0001-0001-000000000001'; // Active

    public const PROJECT_HARTWELL_ID = 'b0000001-0001-0001-0001-000000000002'; // Completed

    public const PROJECT_SUNRISE_ID = 'b0000001-0001-0001-0001-000000000003'; // Not Started

    public const PROJECT_NOVA1_ID = 'b0000001-0001-0001-0001-000000000004'; // Nova

    // ── Milestones ───────────────────────────────────────────────────────
    public const MILESTONE_APEX_1_ID = 'c0000001-0001-0001-0001-000000000001';

    public const MILESTONE_APEX_2_ID = 'c0000001-0001-0001-0001-000000000002';

    public const MILESTONE_MERIDIAN_1_ID = 'c0000001-0001-0001-0001-000000000003';

    // ── Invoices ─────────────────────────────────────────────────────────
    public const INVOICE_APEX_PAID_ID = 'd0000001-0001-0001-0001-000000000001';

    public const INVOICE_APEX_PENDING_ID = 'd0000001-0001-0001-0001-000000000002';

    public const INVOICE_MERIDIAN_ID = 'd0000001-0001-0001-0001-000000000003';

    // ── Company Settings ─────────────────────────────────────────────────
    public const PIXEL_SETTINGS_ID = 'e0000001-0001-0001-0001-000000000001';

    public const NOVA_SETTINGS_ID = 'e0000001-0001-0001-0001-000000000002';

    // ── Password Hash (bcrypt of "Demo@1234" at cost 12) ─────────────────
    // Generated via: password_hash('Demo@1234', PASSWORD_BCRYPT, ['cost' => 12])
    public const PASSWORD_HASH = '$2y$12$pufBk5GrrbpCcIJMD0RkDe5TDlDyfz8FNeLan9mQsoztjeg7SMRyC';
}
