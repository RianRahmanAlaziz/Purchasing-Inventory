<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Purchasing\Support\PoMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'status' => $this->status,
            'supplier_id' => $this->supplier_id,
            'supplier_name_snapshot' => $this->supplier_name_snapshot,
            'supplier_code_snapshot' => $this->supplier_code_snapshot,
            'supplier_address_snapshot' => $this->supplier_address_snapshot,
            'company_id' => $this->company_id,
            'company_snapshot' => $this->company_snapshot,
            'tax_scope' => $this->tax_scope,
            'requested_for_employee_id' => $this->requested_for_employee_id,
            'requested_for_department_id' => $this->requested_for_department_id,
            'created_by' => $this->created_by,
            'order_date' => $this->order_date?->format('Y-m-d'),
            'expected_date' => $this->expected_date?->format('Y-m-d'),
            'subtotal' => $this->subtotal,
            'gross_subtotal' => PoMoney::format(PoMoney::add(PoMoney::cents((string) $this->subtotal), PoMoney::cents((string) $this->discount_total))),
            'discount_total' => $this->discount_total,
            'tax_addition_total' => $this->tax_addition_total,
            'tax_withholding_total' => $this->tax_withholding_total,
            'total_amount' => $this->total_amount,
            'approval_basis_amount' => $this->approval_basis_amount,
            'approval_threshold_amount' => $this->approval_threshold_amount,
            'requires_gm_approval' => $this->requires_gm_approval,
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'supplier_confirmed_at' => $this->supplier_confirmed_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn ($entry): array => [
                'id' => $entry->id,
                'item_id' => $entry->item_id,
                'sku' => $entry->item_sku_snapshot,
                'name' => $entry->item_name_snapshot,
                'unit' => $entry->unit_snapshot,
                'quantity' => $entry->quantity,
                'unit_price' => $entry->unit_price,
                'line_subtotal' => $entry->line_subtotal,
                'discount_percent' => $entry->discount_percent,
                'discount_amount' => $entry->discount_amount,
                'tax_rate_id' => $entry->tax_rate_id,
                'tax_label' => $entry->tax_label_snapshot,
                'tax_direction' => $entry->tax_direction_snapshot,
                'tax_rate_percent' => $entry->tax_rate_percent_snapshot,
                'tax_base_factor' => $entry->tax_base_factor_snapshot,
                'taxable_base_amount' => $entry->taxable_base_amount,
                'tax_amount' => $entry->tax_amount,
                'notes' => $entry->notes,
            ])->all()),
            'taxes' => $this->whenLoaded('taxes', fn (): array => $this->taxes->map(fn ($tax): array => [
                'id' => $tax->id,
                'tax_rate_id' => $tax->tax_rate_id,
                'code' => $tax->tax_code_snapshot,
                'name' => $tax->tax_name_snapshot,
                'direction' => $tax->direction_snapshot,
                'rate_percent' => $tax->rate_percent_snapshot,
                'base_factor' => $tax->base_factor_snapshot,
                'taxable_base_amount' => $tax->taxable_base_amount,
                'tax_amount' => $tax->tax_amount,
            ])->all()),
            'approvals' => $this->whenLoaded('approvals', fn (): array => $this->approvals->map(fn ($approval): array => [
                'id' => $approval->id,
                'step_order' => $approval->step_order,
                'step' => $approval->role_name,
                'status' => $approval->status,
                'decided_by' => $approval->decided_by,
                'decided_by_name' => $approval->decider?->name,
                'decided_at' => $approval->decided_at?->toIso8601String(),
                'rejection_reason' => $approval->rejection_reason,
            ])->all()),
            'order' => $this->whenLoaded('order', fn (): ?array => $this->order ? [
                'id' => $this->order->id,
                'status' => $this->order->status,
                'confirmed_at' => $this->order->confirmed_at?->toIso8601String(),
            ] : null),
            'events' => $this->whenLoaded('events', fn (): array => $this->events->map(fn ($event): array => [
                'action' => $event->action,
                'actor_id' => $event->actor_id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'notes' => $event->notes,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
