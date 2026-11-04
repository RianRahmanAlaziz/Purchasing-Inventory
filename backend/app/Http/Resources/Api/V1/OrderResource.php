<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'status' => $this->status,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn (): ?array => $this->purchaseOrder === null ? null : [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
                'status' => $this->purchaseOrder->status,
                'supplier_id' => $this->purchaseOrder->supplier_id,
                'supplier_name' => $this->purchaseOrder->supplier_name_snapshot,
                'requested_for_employee_id' => $this->purchaseOrder->requested_for_employee_id,
                'requested_for_department_id' => $this->purchaseOrder->requested_for_department_id,
                'order_date' => $this->purchaseOrder->order_date?->format('Y-m-d'),
                'expected_date' => $this->purchaseOrder->expected_date?->format('Y-m-d'),
                'total_amount' => $this->purchaseOrder->total_amount,
                'po_deleted_at' => $this->purchaseOrder->deleted_at?->toIso8601String(),
            ]
            ),
        ];
    }
}
