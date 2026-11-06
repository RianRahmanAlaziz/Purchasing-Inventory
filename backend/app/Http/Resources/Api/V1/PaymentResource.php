<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'payment_number' => $this->payment_number, 'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice?->invoice_number, 'supplier_name' => $this->invoice?->supplier?->name,
            'po_number' => $this->invoice?->purchaseOrder?->po_number,
            'payment_date' => $this->payment_date?->format('Y-m-d'), 'amount' => $this->amount,
            'reference' => $this->reference, 'notes' => $this->notes, 'status' => $this->status,
            'verified_at' => $this->verified_at?->toIso8601String(), 'verified_by' => $this->verified_by,
            'rejection_reason' => $this->rejection_reason, 'created_by' => $this->created_by];
    }
}
