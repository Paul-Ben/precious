<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'uuid'],
            'auditable_type' => ['nullable', 'string', 'max:100'],
            'auditable_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('actor')
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_id', $id))
            ->when($filters['auditable_type'] ?? null, fn ($q, $type) => $q->where('auditable_type', $type))
            ->when($filters['auditable_id'] ?? null, fn ($q, $id) => $q->where('auditable_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to))
            ->latest('created_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ApiResponse::success(AuditLogResource::collection($logs));
    }
}
