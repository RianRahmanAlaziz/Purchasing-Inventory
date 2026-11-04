<?php

declare(strict_types=1);

namespace App\Domain\Receiving\Exceptions;

use RuntimeException;

final class GoodsReceiptConflict extends RuntimeException {}
