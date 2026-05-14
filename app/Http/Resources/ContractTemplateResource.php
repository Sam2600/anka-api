<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'is_global' => $this->tenant_id === null,
            'name' => $this->name,
            'slug' => $this->slug,
            'umbrella' => $this->umbrella,
            'version' => $this->version,
            // Sections JSON is large — only include when explicitly requested
            // (e.g. show endpoint). List endpoint omits to keep payload slim.
            'sections' => $this->when(
                $request->routeIs('contract-templates.show') || $request->boolean('include_sections'),
                $this->sections,
            ),
            'section_count' => is_array($this->sections) ? count($this->sections) : 0,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
