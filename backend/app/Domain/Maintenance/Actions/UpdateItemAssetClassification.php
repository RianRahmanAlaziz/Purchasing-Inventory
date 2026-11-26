<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Item\Models\Item;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateItemAssetClassification
{
    public function execute(
        int $itemId,
        bool $isAssetTrackable,
        string $reason,
        int $actorId,
    ): Item {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException(
                'Alasan klasifikasi minimal 10 karakter.'
            );
        }

        return DB::transaction(function () use (
            $itemId,
            $isAssetTrackable,
            $reason,
            $actorId,
        ): Item {
            $actor = User::query()->findOrFail($actorId);

            $allowedRole = $actor->hasRole('super_admin', 'web')
                || $actor->hasRole('hr', 'web');

            if (
                ! $actor->is_active
                || ! $allowedRole
                || ! $actor->can('maintenance.asset-items.classify')
            ) {
                throw new AuthorizationException(
                    'Anda tidak memiliki izin klasifikasi aset.'
                );
            }

            $item = Item::query()
                ->lockForUpdate()
                ->findOrFail($itemId);

            $before = (bool) $item->is_asset_trackable;

            // Request dengan nilai yang sama tidak
            // membuat perubahan atau audit duplikat.
            if ($before === $isAssetTrackable) {
                return $item;
            }

            $item->is_asset_trackable = $isAssetTrackable;
            $item->save();

            DB::table('maintenance_item_classification_events')
                ->insert([
                    'item_id' => $item->id,
                    'item_sku_snapshot' => $item->sku,
                    'changed_by' => $actor->id,
                    'before_value' => $before,
                    'after_value' => $isAssetTrackable,
                    'reason' => $reason,
                    'created_at' => now(),
                ]);

            return $item->refresh();
        }, attempts: 3);
    }
}
