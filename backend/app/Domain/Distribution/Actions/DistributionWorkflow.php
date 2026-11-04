<?php

declare(strict_types=1);

namespace App\Domain\Distribution\Actions;

use App\Domain\Distribution\Enums\DistributionStatus as Status;
use App\Domain\Distribution\Exceptions\DistributionConflict;
use App\Domain\Distribution\Models\Distribution;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Item\Models\Item;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DistributionWorkflow
{
    public function __construct(private readonly StockService $stock) {}

    public function approve(Distribution $distribution, User $actor): Distribution
    {
        $this->needs($actor, 'distribution.approve');

        return DB::transaction(function () use ($distribution, $actor): Distribution {
            $locked = $this->lock($distribution);
            $this->requireStatus($locked, Status::Requested);
            $this->preventSelfApproval($locked, $actor);
            $this->ensureActiveRecipient($locked);
            $locked->update([
                'status' => Status::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->event($locked, $actor, 'approved', Status::Requested->value);

            return $this->loaded($locked);
        }, attempts: 3);
    }

    public function reject(Distribution $distribution, User $actor, string $reason): Distribution
    {
        $this->needs($actor, 'distribution.reject');
        $reason = trim($reason);
        if ($reason === '') {
            throw new DistributionConflict('Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($distribution, $actor, $reason): Distribution {
            $locked = $this->lock($distribution);
            $this->requireStatus($locked, Status::Requested);
            $this->preventSelfApproval($locked, $actor);
            $locked->update([
                'status' => Status::Cancelled->value,
                'cancelled_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $this->event($locked, $actor, 'rejected', Status::Requested->value, $reason);

            return $this->loaded($locked);
        }, attempts: 3);
    }

    public function prepare(Distribution $distribution, User $actor): Distribution
    {
        $this->needs($actor, 'distribution.prepare');

        return DB::transaction(function () use ($distribution, $actor): Distribution {
            $locked = $this->lock($distribution);
            $this->requireStatus($locked, Status::Approved);
            $this->ensureActiveRecipient($locked);
            $locked->update(['status' => Status::Preparing->value]);
            $this->event($locked, $actor, 'preparing', Status::Approved->value);

            return $this->loaded($locked);
        }, attempts: 3);
    }

    public function send(Distribution $distribution, User $actor): Distribution
    {
        $this->needs($actor, 'distribution.send');

        return DB::transaction(function () use ($distribution, $actor): Distribution {
            // Kunci distribusi dahulu; semua stok barang dikunci oleh StockService
            // dalam urutan item_id supaya pengiriman bersamaan konsisten.
            $locked = $this->lock($distribution);
            $lines = $locked->items()->orderBy('item_id')->get();
            if ($lines->isEmpty()) {
                throw new DistributionConflict('Distribusi tidak memiliki item.');
            }

            $lineIds = $lines->pluck('id')->all();
            $movementCount = StockMovement::query()
                ->whereIn('distribution_item_id', $lineIds)->count();

            // Request yang berhasil dikirim sebelumnya adalah replay, bukan
            // pengiriman kedua. Jika audit tidak lengkap, tolak sebagai konflik.
            if (in_array($locked->status, [Status::Sent->value, Status::ReceivedByEmployee->value], true)) {
                if ($locked->sent_at === null || $movementCount !== $lines->count()) {
                    throw new DistributionConflict('Status pengiriman dan audit stok tidak konsisten.');
                }

                return $this->loaded($locked);
            }

            $this->requireStatus($locked, Status::Preparing);
            $this->ensureActiveRecipient($locked);
            if (Item::query()->whereIn('id', $lines->pluck('item_id'))
                ->where('is_active', true)->count() !== $lines->count()) {
                throw new DistributionConflict('Barang tidak aktif tidak dapat dikirim.');
            }
            if ($movementCount !== 0) {
                throw new DistributionConflict('Distribusi belum Sent tetapi sudah memiliki mutasi stok.');
            }

            $locked->update([
                'status' => Status::Sent->value,
                'sent_at' => now(),
                'sent_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                // Method ini mewajibkan status Sent + sent_at + sent_by
                // dan mengunci item, lalu mencegah saldo negatif.
                $this->stock->issueSentDistributionItem((int) $line->id, (int) $actor->id);
            }

            $this->event($locked, $actor, 'sent', Status::Preparing->value);

            return $this->loaded($locked);
        }, attempts: 3);
    }

    public function receive(Distribution $distribution, User $actor): Distribution
    {
        return DB::transaction(function () use ($distribution, $actor): Distribution {
            $locked = $this->lock($distribution);
            $employee = Employee::query()->whereKey($locked->employee_id)
                ->where('user_id', $actor->id)->where('is_active', true)->first();

            if ($employee === null || ! $actor->tokenCan('employee:self')) {
                throw new AuthorizationException('Hanya karyawan penerima yang dapat mengonfirmasi.');
            }

            if ($locked->status === Status::ReceivedByEmployee->value) {
                if ((int) $locked->received_by !== (int) $actor->id) {
                    throw new DistributionConflict('Penerimaan sebelumnya dilakukan oleh akun berbeda.');
                }

                return $this->loaded($locked);
            }

            $this->requireStatus($locked, Status::Sent);
            $lineIds = $locked->items()->pluck('id');
            if ($lineIds->isEmpty()
                || StockMovement::query()->whereIn('distribution_item_id', $lineIds)->count() !== $lineIds->count()) {
                throw new DistributionConflict('Pengiriman belum memiliki audit stok lengkap.');
            }

            $locked->update([
                'status' => Status::ReceivedByEmployee->value,
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);
            $this->event($locked, $actor, 'received', Status::Sent->value);

            return $this->loaded($locked);
        }, attempts: 3);
    }

    private function lock(Distribution $distribution): Distribution
    {
        return Distribution::query()->lockForUpdate()->findOrFail($distribution->getKey());
    }

    private function needs(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException('Permission distribusi tidak dimiliki.');
        }
    }

    private function preventSelfApproval(Distribution $distribution, User $actor): void
    {
        if ((int) $distribution->requested_by === (int) $actor->id) {
            throw new DistributionConflict('Pengaju tidak boleh menyetujui/menolak permintaannya sendiri.');
        }
    }

    private function ensureActiveRecipient(Distribution $distribution): void
    {
        if (! Employee::query()->whereKey($distribution->employee_id)
            ->where('is_active', true)->exists()) {
            throw new DistributionConflict('Karyawan penerima tidak aktif.');
        }
    }

    private function requireStatus(Distribution $distribution, Status $status): void
    {
        if ($distribution->status !== $status->value) {
            throw new DistributionConflict('Perubahan status distribusi tidak diizinkan. Status saat ini: '.$distribution->status);
        }
    }

    private function event(
        Distribution $distribution,
        User $actor,
        string $action,
        ?string $from,
        ?string $notes = null,
    ): void {
        $distribution->events()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $distribution->status,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function loaded(Distribution $distribution): Distribution
    {
        return $distribution->refresh()->load([
            'employee.department', 'items.item', 'items.movement', 'events',
        ]);
    }
}
