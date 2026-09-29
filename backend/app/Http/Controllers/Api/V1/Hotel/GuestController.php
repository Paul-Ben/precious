<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Guests\GuestService;
use App\Enums\GuestDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\GuestDocumentRequest;
use App\Http\Requests\Hotel\GuestRequest;
use App\Http\Resources\GuestDocumentResource;
use App\Http\Resources\GuestResource;
use App\Http\Resources\ReservationResource;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestController extends Controller
{
    public function __construct(private readonly GuestService $guests) {}

    /**
     * Search by name, phone, email or reservation number (spec §56).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'vip' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $guests = Guest::query()
            ->withCount('reservations')
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $digits = preg_replace('/\D+/', '', $search);

                $q->where(fn (Builder $w) => $w
                    ->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->when(strlen($digits) >= 4, fn ($p) => $p->orWhere('phone', 'like', '%'.$digits.'%'))
                    ->orWhereHas('reservations', fn (Builder $r) => $r->whereRaw('LOWER(number) LIKE ?', [$term])));
            })
            ->when(isset($filters['vip']), fn ($q) => $q->where('is_vip', (bool) $filters['vip']))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return ApiResponse::success(GuestResource::collection($guests));
    }

    public function store(GuestRequest $request): JsonResponse
    {
        return ApiResponse::created(new GuestResource($this->guests->create($request->validated())), 'Guest created.');
    }

    public function show(Guest $guest): JsonResponse
    {
        return ApiResponse::success(new GuestResource($guest->loadCount('reservations')));
    }

    public function update(GuestRequest $request, Guest $guest): JsonResponse
    {
        return ApiResponse::success(new GuestResource($this->guests->update($guest, $request->validated())), 'Guest updated.');
    }

    public function destroy(Guest $guest): JsonResponse
    {
        $this->guests->delete($guest);

        return ApiResponse::success(null, 'Guest deleted.');
    }

    /** Guest history: every reservation the guest booked or stayed on. */
    public function reservations(Guest $guest): JsonResponse
    {
        $list = $guest->stays()
            ->with(['guest', 'rooms.room', 'rooms.roomType'])
            ->orderByDesc('check_in')
            ->paginate(20);

        $resource = ReservationResource::collection($list);
        $resource->collection->each->forStaff();

        return ApiResponse::success($resource);
    }

    // ---------------------------------------------------------- documents

    public function documents(Guest $guest): JsonResponse
    {
        return ApiResponse::success(GuestDocumentResource::collection($guest->documents()->with('uploader')->get()));
    }

    public function storeDocument(GuestDocumentRequest $request, Guest $guest): JsonResponse
    {
        $data = $request->validated();
        $document = $this->guests->storeDocument(
            $guest,
            $request->file('file'),
            GuestDocumentType::from($data['type']),
            $data['number'] ?? null,
            $data['expires_on'] ?? null,
            $request->user(),
        );

        return ApiResponse::created(new GuestDocumentResource($document->load('uploader')), 'Document uploaded.');
    }

    public function downloadDocument(Guest $guest, GuestDocument $document): StreamedResponse
    {
        return $this->guests->downloadDocument($guest, $document);
    }

    public function verifyDocument(Request $request, Guest $guest, GuestDocument $document): JsonResponse
    {
        $document = $this->guests->verifyDocument($guest, $document, $request->user());

        return ApiResponse::success(new GuestDocumentResource($document->load('uploader')), 'Document marked as verified.');
    }

    public function destroyDocument(Guest $guest, GuestDocument $document): JsonResponse
    {
        $this->guests->deleteDocument($guest, $document);

        return ApiResponse::success(null, 'Document deleted.');
    }
}
