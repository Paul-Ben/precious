<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Domain\Staff\StaffService;
use App\Enums\EmploymentStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Staff\StaffResource;
use App\Models\Department;
use App\Models\Property;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff directory and records (spec §30, P23). Accounts and roles stay in Users. */
class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
            'all' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $this->staff->ensureAllProfiles();

        $query = User::query()
            ->where('type', UserType::Staff->value)
            ->with(['roles', 'staffProfile.department'])
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $term = '%'.mb_strtolower($search).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereHas('staffProfile', fn ($p) => $p->whereRaw('LOWER(employee_number) LIKE ?', [$term])->orWhereRaw('LOWER(position) LIKE ?', [$term])));
            })
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->whereHas('staffProfile', fn ($p) => $p->where('department_id', $id)))
            ->when($filters['employment_status'] ?? null, fn ($q, $s) => $q->whereHas('staffProfile', fn ($p) => $p->where('employment_status', $s)))
            ->orderBy('name');

        // `all=1` (rota rows, pickers): every schedulable person, unpaginated.
        if ($request->boolean('all')) {
            $users = $query->whereHas('staffProfile', fn ($p) => $p->where('employment_status', '!=', EmploymentStatus::Left->value))
                ->where('status', 'active')
                ->get();

            return ApiResponse::success(StaffResource::collection($users));
        }

        return ApiResponse::success(StaffResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString()));
    }

    public function show(User $user): JsonResponse
    {
        abort_unless($user->isStaff(), 404);
        $this->staff->ensureProfile($user);

        return ApiResponse::success(new StaffResource($user->load(['roles', 'staffProfile.department'])));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isStaff(), 404);

        $data = $request->validate([
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')->where('property_id', Property::current()->id)],
            'position' => ['sometimes', 'nullable', 'string', 'max:80'],
            'employment_status' => ['sometimes', Rule::enum(EmploymentStatus::class)],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{6,32}$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $this->staff->update($user, $data, $request->user());

        return ApiResponse::success(new StaffResource($user->refresh()->load(['roles', 'staffProfile.department'])), 'Staff record saved.');
    }

    public function storePhoto(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isStaff(), 404);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']]);

        $this->staff->storePhoto($user, $request->file('photo'));

        return ApiResponse::success(new StaffResource($user->refresh()->load(['roles', 'staffProfile.department'])), 'Photo updated.');
    }

    public function photo(User $user): StreamedResponse
    {
        abort_unless($user->isStaff(), 404);

        return $this->staff->photo($user);
    }

    public function destroyPhoto(User $user): JsonResponse
    {
        abort_unless($user->isStaff(), 404);
        $this->staff->deletePhoto($user);

        return ApiResponse::success(null, 'Photo removed.');
    }

    public function departments(): JsonResponse
    {
        return ApiResponse::success(
            Department::query()->where('property_id', Property::current()->id)->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'code'])
        );
    }
}
