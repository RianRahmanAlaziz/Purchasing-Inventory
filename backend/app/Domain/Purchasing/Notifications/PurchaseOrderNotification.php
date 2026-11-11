<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Notifications;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PurchaseOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'purchase_order_approval';

    public int $tries = 3;

    public array $backoff = [60, 180];

    public function __construct(
        public readonly int $purchaseOrderId,
        public readonly string $purchaseOrderNumber,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $pendingRole = null,
        public readonly ?string $reason = null,
    ) {}

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function databaseType(User $notifiable): string
    {
        return self::TYPE;
    }

    public function toDatabase(User $notifiable): array
    {
        return [
            'purchase_order_id' => $this->purchaseOrderId,
            'purchase_order_number' => $this->purchaseOrderNumber,
            'title' => $this->title,
            'message' => $this->message,
            'target' => $this->target(),
            'reason' => $this->reason,
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $destination = '/'.$this->target().'?po='.$this->purchaseOrderId;
        $url = rtrim((string) config('purchasing.frontend_url'), '/').'/login?next='.rawurlencode($destination);

        return (new MailMessage)
            ->subject($this->title.' · '.$this->purchaseOrderNumber)
            ->greeting('Pemberitahuan Purchase Order')
            ->line($this->message)
            ->action('Buka PO', $url)
            ->salutation('RIS Purchasing');
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        if (! $notifiable->is_active || ! $notifiable->can('po.view')) {
            return false;
        }

        if ($channel !== 'mail' || $this->pendingRole === null) {
            return true;
        }

        $step = $this->pendingRole === 'general_manager' ? 'gm' : 'hr';
        if (! $notifiable->hasRole($this->pendingRole, 'web')
            || (! $notifiable->can('po.approve.'.$step) && ! $notifiable->can('po.reject.'.$step))) {
            return false;
        }

        $po = PurchaseOrder::query()->find($this->purchaseOrderId);

        return $po?->status === 'pending_approval'
            && $po->approvals()->where('status', 'pending')->value('role_name') === $this->pendingRole;
    }

    private function target(): string
    {
        return $this->pendingRole === null ? 'purchase-orders' : 'approvals';
    }
}
