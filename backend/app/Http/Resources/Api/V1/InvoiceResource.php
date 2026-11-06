<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Purchasing\Support\PoMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = PoMoney::cents((string) $this->total_amount);
        $paid = PoMoney::cents((string) ($this->verified_amount ?? '0'));
        $pending = PoMoney::cents((string) ($this->pending_amount ?? '0'));
        $remaining = PoMoney::subtract($total, $paid);
        $status = $remaining === 0 ? 'paid' : ($pending > 0 ? 'pending_verification' : ($paid > 0 ? 'partially_paid' : 'unpaid'));
        $overdue = $remaining > 0 && $this->due_date !== null
            && $this->due_date->format('Y-m-d') < now('Asia/Bangkok')->format('Y-m-d');

        return ['id' => $this->id, 'invoice_number' => $this->invoice_number,
            'supplier_id' => $this->supplier_id, 'supplier_name' => $this->supplier?->name,
            'purchase_order_id' => $this->purchase_order_id, 'po_number' => $this->purchaseOrder?->po_number,
            'invoice_date' => $this->invoice_date?->format('Y-m-d'), 'due_date' => $this->due_date?->format('Y-m-d'),
            'total_amount' => $this->total_amount, 'paid_amount' => PoMoney::format($paid),
            'pending_amount' => PoMoney::format($pending), 'remaining_amount' => PoMoney::format($remaining),
            'available_amount' => PoMoney::format(PoMoney::subtract($remaining, $pending)),
            'status' => $overdue ? 'overdue' : $status, 'payment_status' => $status, 'is_overdue' => $overdue,
            'can_delete' => (int) $this->payment_history_count === 0,
            'notes' => $this->notes, 'created_by' => $this->created_by];
    }
}
