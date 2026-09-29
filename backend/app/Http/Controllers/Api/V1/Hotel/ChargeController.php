<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Stays\FolioService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stays\AddChargeRequest;
use App\Http\Resources\ChargeResource;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationCharge;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChargeController extends Controller
{
    public function __construct(private readonly FolioService $folio) {}

    public function store(AddChargeRequest $request, string $reservation): JsonResponse
    {
        // Reductions are discounts: they need the approval permission, not just services.charge.
        if ($request->input('category') === 'ADJUSTMENT' && ! $request->user()->can('discounts.approve')) {
            return ApiResponse::error('You do not have permission to reduce a bill.', 403, 'FORBIDDEN');
        }

        $model = Reservation::query()->where('property_id', Property::current()->id)->findOrFail($reservation);
        $charge = $this->folio->addCharge($model, $request->validated(), $request->user());

        return ApiResponse::created(new ChargeResource($charge->load('createdBy')), 'Added to the bill.');
    }

    public function void(Request $request, string $charge): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        $model = ReservationCharge::query()
            ->whereHas('reservation', fn ($q) => $q->where('property_id', Property::current()->id))
            ->findOrFail($charge);

        return ApiResponse::success(new ChargeResource($this->folio->voidCharge($model, $reason, $request->user())->load(['createdBy', 'voidedBy'])), 'Charge voided.');
    }
}
