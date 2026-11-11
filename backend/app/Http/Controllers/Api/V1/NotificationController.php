<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchasing\Notifications\PurchaseOrderNotification;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,100000']]);
        $inbox = $this->inbox($request->user());
        $page = (clone $inbox)->orderByDesc('id')->paginate(10);

        return ApiResponse::success([
            'unread_count' => $inbox->whereNull('read_at')->count(),
            'notifications' => $page->getCollection()->map(static fn (DatabaseNotification $notification): array => [
                ...$notification->data,
                'id' => $notification->id,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at->toIso8601String(),
            ])->all(),
            'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $this->inbox($request->user())->findOrFail($notification)->markAsRead();

        return ApiResponse::success(message: 'Notifikasi sudah ditandai dibaca.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->inbox($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(message: 'Semua notifikasi sudah ditandai dibaca.');
    }

    private function inbox(User $user): MorphMany
    {
        return $user->notifications()->where('type', PurchaseOrderNotification::TYPE);
    }
}
