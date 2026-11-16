<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderCancellation;
use App\Domain\Purchasing\Notifications\PurchaseOrderNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class NotifyPurchaseOrderApproval
{
    public function request(PurchaseOrder $po, string $step, ?int $previousReviewer = null): void
    {
        $role = $step === 'gm' ? 'general_manager' : 'hr';
        $reviewers = User::query()->where('is_active', true)
            ->whereKeyNot($po->created_by)
            ->when($previousReviewer !== null, fn ($query) => $query->whereKeyNot($previousReviewer))
            ->whereHas('roles', fn ($query) => $query->where('name', $role)->where('guard_name', 'web'))
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->filter(fn (User $user): bool => $user->can('po.view') && (
                PurchaseOrderWorkflow::canDecide($user, $step, 'approve')
                || PurchaseOrderWorkflow::canDecide($user, $step, 'reject')
            ));

        $stage = strtoupper($step);
        $this->deliver($reviewers, new PurchaseOrderNotification(
            (int) $po->id,
            $po->po_number,
            'PO menunggu persetujuan '.$stage,
            "PO {$po->po_number} siap diperiksa pada tahap {$stage}.",
            pendingRole: $role,
        ));
    }

    public function approved(PurchaseOrder $po): void
    {
        $finished = $po->status === 'approved';
        $this->notifyCreator($po, new PurchaseOrderNotification(
            (int) $po->id,
            $po->po_number,
            $finished ? 'PO disetujui' : 'PO disetujui HR',
            $finished ? "PO {$po->po_number} telah disetujui." : "PO {$po->po_number} menunggu persetujuan GM.",
        ));
    }

    public function rejected(PurchaseOrder $po, string $step, string $reason): void
    {
        $stage = strtoupper($step);
        $this->notifyCreator($po, new PurchaseOrderNotification(
            (int) $po->id,
            $po->po_number,
            'PO ditolak',
            "PO {$po->po_number} ditolak pada tahap {$stage}.",
            reason: $reason,
        ));
    }

    public function cancellationRequested(PurchaseOrder $po, PurchaseOrderCancellation $cancellation): void
    {
        $reviewers = User::query()->where('is_active', true)
            ->whereKeyNot($cancellation->requested_by)
            ->whereHas('roles', fn ($query) => $query->where('name', 'hr')->where('guard_name', 'web'))
            ->with(['roles.permissions', 'permissions'])->get()
            ->filter(fn (User $user): bool => $user->can('po.view') && (
                PurchaseOrderWorkflow::canDecideCancellation($user, 'approve')
                || PurchaseOrderWorkflow::canDecideCancellation($user, 'reject')
            ));

        $this->deliver($reviewers, new PurchaseOrderNotification(
            (int) $po->id,
            $po->po_number,
            'Permintaan pembatalan PO',
            "PO {$po->po_number} menunggu keputusan HR untuk pembatalan.",
            pendingRole: 'hr',
            cancellationId: (int) $cancellation->id,
        ));
    }

    public function cancellationDecided(PurchaseOrder $po, PurchaseOrderCancellation $cancellation): void
    {
        $accepted = $cancellation->status === 'approved';
        $recipients = User::query()->whereKey([$po->created_by, $cancellation->requested_by])->where('is_active', true)
            ->with(['roles.permissions', 'permissions'])->get()->filter(fn (User $user): bool => $user->can('po.view'));

        $this->deliver($recipients, new PurchaseOrderNotification(
            (int) $po->id,
            $po->po_number,
            $accepted ? 'Pembatalan PO disetujui' : 'Pembatalan PO ditolak',
            $accepted ? "PO {$po->po_number} dibatalkan oleh HR." : "PO {$po->po_number} tetap berlaku.",
            reason: $cancellation->rejection_reason,
        ));
    }

    private function notifyCreator(PurchaseOrder $po, PurchaseOrderNotification $notification): void
    {
        $creator = $po->creator()->first();
        if ($creator !== null && $creator->is_active && $creator->can('po.view')) {
            $this->deliver(collect([$creator]), $notification);
        }
    }

    /** @param Collection<int, User> $recipients */
    private function deliver(Collection $recipients, PurchaseOrderNotification $notification): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::sendNow($recipients, $notification, ['database']);
        DB::afterCommit(static function () use ($recipients, $notification): void {
            foreach ($recipients as $user) {
                try {
                    $user->notify($notification);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }
}
