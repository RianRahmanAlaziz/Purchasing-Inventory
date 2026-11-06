<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'document_number' => $this->document_number, 'document_type' => $this->document_type,
            'supplier_id' => $this->supplier_id, 'supplier_name' => $this->supplier?->name,
            'purchase_order_id' => $this->purchase_order_id, 'po_number' => $this->purchaseOrder?->po_number,
            'document_date' => $this->document_date?->format('Y-m-d'), 'status' => $this->status,
            'file_name' => $this->file_name, 'file_mime' => $this->file_mime, 'file_size' => $this->file_size,
            'notes' => $this->notes, 'sent_at' => $this->sent_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(), 'verified_at' => $this->verified_at?->toIso8601String()];
    }
}
