<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE estimation_versions (
            id UUID PRIMARY KEY,
            tenant_id UUID NOT NULL,
            deal_id UUID NOT NULL,
            version_number INTEGER NOT NULL,
            resources JSONB NOT NULL DEFAULT \'[]\',
            overheads JSONB NOT NULL DEFAULT \'[]\',
            target_margin NUMERIC(5,2) NOT NULL DEFAULT 0,
            notes TEXT,
            created_by UUID,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
            FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE,
            CONSTRAINT unique_version_per_deal UNIQUE (deal_id, version_number)
        )');

        DB::statement('CREATE INDEX idx_estimation_versions_deal ON estimation_versions(deal_id, version_number DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('estimation_versions');
    }
};
