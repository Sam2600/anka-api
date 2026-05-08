<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION win_deal(p_deal_id UUID, p_tenant_id UUID)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_deal RECORD;
    v_contract_id UUID;
    v_project_id UUID;
    v_total_value NUMERIC(14,2);
    v_budget_hours NUMERIC(10,2);
BEGIN
    SELECT * INTO v_deal
    FROM deals
    WHERE id = p_deal_id
      AND tenant_id = p_tenant_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Deal % not found for tenant %', p_deal_id, p_tenant_id;
    END IF;

    IF v_deal.status = 'won' THEN
        SELECT id INTO v_contract_id
        FROM contracts
        WHERE deal_id = p_deal_id
        LIMIT 1;

        IF v_contract_id IS NOT NULL THEN
            RETURN;
        END IF;

    END IF;

    IF v_deal.status = 'lost' THEN
        RAISE EXCEPTION 'Deal % is already lost', p_deal_id;
    END IF;

    v_total_value := COALESCE(v_deal.client_budget, v_deal.estimated_value, 0);
    v_budget_hours := COALESCE(v_deal.workload_hours, 0);

    INSERT INTO contracts (
        id, tenant_id, deal_id, client, total_value,
        revenue_recognized, status, start_date, end_date, notes,
        created_at, updated_at
    ) VALUES (
        gen_random_uuid(),
        p_tenant_id,
        p_deal_id,
        COALESCE(v_deal.client, ''),
        v_total_value,
        0,
        'Draft',
        CURRENT_DATE,
        NULL,
        NULL,
        NOW(),
        NOW()
    )
    RETURNING id INTO v_contract_id;

    INSERT INTO projects (
        id, tenant_id, contract_id, name, client,
        budget_hours, consumed_hours, status, start_date, end_date,
        created_at, updated_at
    ) VALUES (
        gen_random_uuid(),
        p_tenant_id,
        v_contract_id,
        COALESCE(v_deal.name, ''),
        COALESCE(v_deal.client, ''),
        v_budget_hours,
        0,
        'Not Started',
        CURRENT_DATE,
        NULL,
        NOW(),
        NOW()
    )
    RETURNING id INTO v_project_id;

    INSERT INTO project_team_assignments (id, tenant_id, project_id, employee_id, allocated_hours, assignment_source, created_at, updated_at)
    SELECT gen_random_uuid(), p_tenant_id, v_project_id, dha.employee_id, dha.allocated_hours, 'deal_transfer', NOW(), NOW()
    FROM deal_hard_assignments dha
    WHERE dha.deal_id = p_deal_id;

    IF v_deal.status != 'won' THEN
        UPDATE deals
        SET
            status = 'won',
            win_probability = 100,
            won_at = NOW(),
            updated_at = NOW()
        WHERE id = p_deal_id;
    END IF;

END;
$$;
SQL
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION win_deal(p_deal_id UUID, p_tenant_id UUID)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_deal RECORD;
    v_contract_id UUID;
    v_project_id UUID;
    v_total_value NUMERIC(14,2);
    v_budget_hours NUMERIC(10,2);
BEGIN
    SELECT * INTO v_deal
    FROM deals
    WHERE id = p_deal_id
      AND tenant_id = p_tenant_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Deal % not found for tenant %', p_deal_id, p_tenant_id;
    END IF;

    IF v_deal.status = 'won' THEN
        SELECT id INTO v_contract_id
        FROM contracts
        WHERE deal_id = p_deal_id
        LIMIT 1;

        IF v_contract_id IS NOT NULL THEN
            RETURN;
        END IF;

    END IF;

    IF v_deal.status = 'lost' THEN
        RAISE EXCEPTION 'Deal % is already lost', p_deal_id;
    END IF;

    v_total_value := COALESCE(v_deal.client_budget, v_deal.estimated_value, 0);
    v_budget_hours := COALESCE(v_deal.workload_hours, 0);

    INSERT INTO contracts (
        id, tenant_id, deal_id, client, total_value,
        revenue_recognized, status, start_date, end_date, notes,
        created_at, updated_at
    ) VALUES (
        gen_random_uuid(),
        p_tenant_id,
        p_deal_id,
        COALESCE(v_deal.client, ''),
        v_total_value,
        0,
        'Draft',
        CURRENT_DATE,
        NULL,
        NULL,
        NOW(),
        NOW()
    )
    RETURNING id INTO v_contract_id;

    INSERT INTO projects (
        id, tenant_id, contract_id, name, client,
        budget_hours, consumed_hours, status, start_date, end_date,
        created_at, updated_at
    ) VALUES (
        gen_random_uuid(),
        p_tenant_id,
        v_contract_id,
        COALESCE(v_deal.name, ''),
        COALESCE(v_deal.client, ''),
        v_budget_hours,
        0,
        'Not Started',
        CURRENT_DATE,
        NULL,
        NOW(),
        NOW()
    )
    RETURNING id INTO v_project_id;

    IF v_deal.status != 'won' THEN
        UPDATE deals
        SET
            status = 'won',
            win_probability = 100,
            won_at = NOW(),
            updated_at = NOW()
        WHERE id = p_deal_id;
    END IF;

END;
$$;
SQL
        );
    }
};
