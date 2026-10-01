<?php

namespace App\Http\Resources\Staff;

use App\Models\ShiftTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShiftTemplate
 */
class ShiftTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_time' => $this->startHm(),
            'end_time' => $this->endHm(),
            'overnight' => $this->isOvernight(),
            'is_active' => $this->is_active,
        ];
    }
}
