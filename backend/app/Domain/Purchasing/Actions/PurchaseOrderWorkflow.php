<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Exceptions\PurchaseOrderConflict;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderApproval;
use App\Domain\Purchasing\Support\PoMoney;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderWorkflow
{
    public function submit(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $po = $this->locked($po);
            if ($po->status === 'pending_approval') {
                return $this->load($po); // replay aman
            }
            $this->requireStatus($po, 'draft');

            if (
                ! $po->supplier()->where('is_active', true)->exists()
                || ! $po->employee()->where('is_active', true)->exists()
                || ! $po->department()->where('is_active', true)->exists()
            ) {
                throw new PurchaseOrderConflict('Supplier, karyawan, atau departemen tidak aktif.');
            }
            if ((int) $po->employee->department_id !== (int) $po->requested_for_department_id) {
                throw new PurchaseOrderConflict('Karyawan sudah berpindah departemen. Perbarui Draft terlebih dahulu.');
            }
            if (! $po->items()->exists()) {
                throw new PurchaseOrderConflict('PO minimal memiliki satu item.');
            }
            if (
                $po->items()->whereHas('item', fn ($q) => $q->where('is_active', false))->exists()
                || $po->items()->whereHas('item.category', fn ($q) => $q->where('is_active', false))->exists()
            ) {
                throw new PurchaseOrderConflict('PO mempunyai barang atau kategori yang tidak aktif.');
            }

            $basis = PoMoney::cents((string) $po->approval_basis_amount);
            $threshold = PoMoney::cents((string) config('purchasing.gm_approval_threshold', '20000000.00'));
            $needsGm = $basis > $threshold;
            $po->update([
                'status' => 'pending_approval',
                'submitted_at' => now(),
                'approval_threshold_amount' => PoMoney::format($threshold),
                'requires_gm_approval' => $needsGm,
            ]);

            $po->approvals()->create([
                'step_order' => 1,
                'role_name' => 'hr',
                'status' => 'pending',
            ]);
            if ($needsGm) {
                $po->approvals()->create([
                    'step_order' => 2,
                    'role_name' => 'general_manager',
                    'status' => 'pending',
                ]);
            }
            $this->event($po, $actor, 'submitted', 'draft', 'pending_approval');

            return $this->load($po);
        }, attempts: 3);
    }

    public function approve(PurchaseOrder $po, User $actor, string $step): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $step): PurchaseOrder {
            $po = $this->locked($po);
            $actor->can('po.approve.'.$step) || throw new AuthorizationException('Anda tidak memiliki izin menyetujui langkah ini.');
            $approval = $this->step($po, $step);
            $this->makerChecker($po, $actor);

            if ($approval->status === 'approved' && (int) $approval->decided_by === (int) $actor->id) {
                return $this->load($po); // idem: tidak membuat Order lagi
            }
            $this->requireStatus($po, 'pending_approval');
            if ($approval->status !== 'pending') {
                throw new PurchaseOrderConflict('Langkah approval sudah diputuskan.');
            }
            $this->checkApprovalSequence($po, $approval, $actor);

            $approval->update([
                'status' => 'approved',
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);
            $this->event($po, $actor, 'approved_'.$step, 'pending_approval', 'pending_approval');

            if (! $po->approvals()->where('status', 'pending')->exists()) {
                $po->update(['status' => 'approved', 'approved_at' => now()]);
                // Index unik orders.purchase_order_id menjadi pelindung kedua.
                $po->order()->firstOrCreate([], ['status' => 'pending']);
                $this->event($po, $actor, 'fully_approved', 'pending_approval', 'approved');
            }

            return $this->load($po);
        }, attempts: 3);
    }

    public function reject(PurchaseOrder $po, User $actor, string $step, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $step, $reason): PurchaseOrder {
            $po = $this->locked($po);
            $actor->can('po.reject.'.$step) || throw new AuthorizationException('Anda tidak memiliki izin menolak langkah ini.');
            $approval = $this->step($po, $step);
            $this->makerChecker($po, $actor);
            $this->requireStatus($po, 'pending_approval');
            if ($approval->status !== 'pending') {
                throw new PurchaseOrderConflict('Langkah approval sudah diputuskan.');
            }
            $this->checkApprovalSequence($po, $approval, $actor);
            $approval->update([
                'status' => 'rejected',
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $po->update(['status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->event($po, $actor, 'rejected_'.$step, 'pending_approval', 'rejected', $reason);

            return $this->load($po);
        }, attempts: 3);
    }

    public function send(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $po = $this->locked($po);
            if ($po->status === 'sent') {
                return $this->load($po);
            }
            $this->requireStatus($po, 'approved');
            if (! $po->order()->exists()) {
                throw new PurchaseOrderConflict('Order hasil approval tidak ditemukan.');
            }
            $po->update(['status' => 'sent', 'sent_at' => now()]);
            $this->event($po, $actor, 'sent', 'approved', 'sent');

            return $this->load($po);
        }, attempts: 3);
    }

    public function supplierConfirm(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $po = $this->locked($po);

            if ($po->status === 'received_by_supplier') {
                return $this->load($po);
            }

            $this->requireStatus($po, 'sent');

            $order = $po->order()->lockForUpdate()->first();

            if ($order === null || ! in_array($order->status, [
                'pending',
                'confirmed',
                'partially_received',
                'completed',
            ], true)) {
                throw new PurchaseOrderConflict('Order tidak berada pada status yang dapat dikonfirmasi.');
            }

            $po->update([
                'status' => 'received_by_supplier',
                'supplier_confirmed_at' => now(),
            ]);

            // Supplier dapat mengonfirmasi PO SETELAH penerimaan fisik via GRN.
            // Dalam kasus itu status Order tidak boleh kembali menjadi "confirmed".
            $changes = [];
            if ($order->status === 'pending') {
                $changes['status'] = 'confirmed';
            }
            if ($order->confirmed_at === null) {
                $changes['confirmed_at'] = now();
            }
            if ($changes !== []) {
                $order->update($changes);
            }

            $this->event($po, $actor, 'supplier_confirmed', 'sent', 'received_by_supplier');

            return $this->load($po);
        }, attempts: 3);
    }

    public function cancel(PurchaseOrder $po, User $actor, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason): PurchaseOrder {
            $po = $this->locked($po);
            if (! in_array($po->status, ['draft', 'pending_approval', 'approved'], true)) {
                throw new PurchaseOrderConflict('PO tidak dapat dibatalkan pada status sekarang.');
            }
            $from = $po->status;
            $po->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $po->approvals()->where('status', 'pending')->update(['status' => 'cancelled']);
            if ($po->order()->exists()) {
                $po->order()->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }
            $this->event($po, $actor, 'cancelled', $from, 'cancelled', $reason);

            return $this->load($po);
        }, attempts: 3);
    }

    public function softDelete(PurchaseOrder $po, User $actor): void
    {
        DB::transaction(function () use ($po, $actor): void {
            $po = $this->locked($po);
            if (! in_array($po->status, ['draft', 'rejected', 'cancelled'], true)) {
                throw new PurchaseOrderConflict('Hanya PO Draft/Rejected/Cancelled yang boleh dihapus.');
            }
            $this->event($po, $actor, 'deleted', $po->status, $po->status);
            $po->delete();
        }, attempts: 3);
    }

    private function locked(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::query()->lockForUpdate()->findOrFail($po->getKey());
    }

    private function step(PurchaseOrder $po, string $step): PurchaseOrderApproval
    {
        $stepOrder = match ($step) {
            'hr' => 1,
            'gm' => 2,
            default => throw new PurchaseOrderConflict('Langkah approval tidak dikenal.'),
        };
        $approval = $po->approvals()->where('step_order', $stepOrder)->lockForUpdate()->first();
        if (! $approval) {
            throw new PurchaseOrderConflict('Langkah approval tidak berlaku pada PO ini.');
        }

        return $approval;
    }

    private function checkApprovalSequence(PurchaseOrder $po, PurchaseOrderApproval $approval, User $actor): void
    {
        if ($approval->step_order === 2) {
            $hr = $po->approvals()->where('step_order', 1)->first();
            if (! $hr || $hr->status !== 'approved') {
                throw new PurchaseOrderConflict('GM hanya boleh memutuskan setelah HR menyetujui PO.');
            }
            if ((int) $hr->decided_by === (int) $actor->id) {
                throw new AuthorizationException('HR dan GM harus merupakan pengguna berbeda.');
            }
        }
    }

    private function makerChecker(PurchaseOrder $po, User $actor): void
    {
        if ((int) $po->created_by === (int) $actor->id) {
            throw new AuthorizationException('Pembuat PO tidak boleh menyetujui atau menolak PO sendiri.');
        }
    }

    private function requireStatus(PurchaseOrder $po, string $required): void
    {
        if ($po->status !== $required) {
            throw new PurchaseOrderConflict("Status PO harus {$required}; saat ini {$po->status}.");
        }
    }

    private function event(PurchaseOrder $po, User $actor, string $action, ?string $from, string $to, ?string $notes = null): void
    {
        $po->events()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function load(PurchaseOrder $po): PurchaseOrder
    {
        return $po->refresh()->load(['items', 'taxes', 'approvals', 'order', 'events']);
    }
}
