<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Domain\Audit\AuditService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Staff\ShiftTemplateResource;
use App\Models\Property;
use App\Models\ShiftTemplate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Standard shifts (P24): Morning, Afternoon, Night and any others the manager adds. */
class ShiftTemplateController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(ShiftTemplateResource::collection(
            ShiftTemplate::query()->where('property_id', Property::current()->id)->orderBy('start_time')->get()
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $template = ShiftTemplate::query()->create([...$this->validated($request), 'property_id' => Property::current()->id]);
        $this->audit->record('shift_templates.created', $template, null, $template->only(['name', 'start_time', 'end_time']));

        return ApiResponse::created(new ShiftTemplateResource($template->refresh()), 'Shift added.');
    }

    public function update(Request $request, ShiftTemplate $shiftTemplate): JsonResponse
    {
        $before = $shiftTemplate->only(['name', 'start_time', 'end_time', 'is_active']);
        $shiftTemplate->fill($this->validated($request, $shiftTemplate))->save();
        [$old, $new] = $this->audit->diff($before, $shiftTemplate->only(array_keys($before)));
        $this->audit->record('shift_templates.updated', $shiftTemplate, $old, $new);

        return ApiResponse::success(new ShiftTemplateResource($shiftTemplate->refresh()), 'Shift saved. Shifts already on the rota keep their times.');
    }

    public function destroy(ShiftTemplate $shiftTemplate): JsonResponse
    {
        $this->audit->record('shift_templates.deleted', $shiftTemplate, $shiftTemplate->only(['name', 'start_time', 'end_time']));
        $shiftTemplate->delete();

        return ApiResponse::success(null, 'Shift removed. Shifts already on the rota are kept.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ShiftTemplate $template = null): array
    {
        $required = $template ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:60', Rule::unique('shift_templates', 'name')->where('property_id', Property::current()->id)->ignore($template?->id)],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i', 'different:start_time'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $data;
    }
}
