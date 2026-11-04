<?php

declare(strict_types=1);

namespace App\Domain\Distribution\Enums;

enum DistributionStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Preparing = 'preparing';
    case Sent = 'sent';
    case ReceivedByEmployee = 'received_by_employee';
    case Cancelled = 'cancelled';
}
