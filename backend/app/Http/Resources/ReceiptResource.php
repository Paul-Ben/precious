<?php

namespace App\Http\Resources;

use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Receipt
 */
class ReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'issued_at' => $this->issued_at->toIso8601String(),
            'emailed_at' => $this->emailed_at?->toIso8601String(),
            'payment_id' => $this->payment_id,
            ...$this->snapshot,
        ];
    }
}
