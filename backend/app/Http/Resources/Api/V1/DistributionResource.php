<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DistributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distribution_number' => $this->distribution_number,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn (): ?array => $this->employee === null
                ? null : [
                    'id' => $this->employee->id,
                    'employee_number' => $this->employee->employee_number,
                    'name' => $this->employee->name,
                    'department_id' => $this->employee->department_id,
                ]),
            'status' => $this->status,
            'purpose' => $this->purpose,
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'sent_by' => $this->sent_by,
            'received_by' => $this->received_by,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(
                static function ($line): array {
                    $item = $line->relationLoaded('item') ? $line->item : null;
                    $movement = $line->relationLoaded('movement') ? $line->movement : null;

                    return [
                        'id' => $line->id,
                        'item_id' => $line->item_id,
                        'sku' => $item?->sku,
                        'name' => $item?->name,
                        'unit' => $item?->unit,
                        'quantity' => $line->quantity,
                        'notes' => $line->notes,
                        'stock_movement_id' => $movement?->id,
                    ];
                }
            )->values()->all()),
            'events' => $this->whenLoaded('events', fn (): array => $this->events->map(
                static fn ($event): array => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'actor_id' => $event->actor_id,
                    'notes' => $event->notes,
                    'created_at' => $event->created_at?->toIso8601String(),
                ]
            )->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
