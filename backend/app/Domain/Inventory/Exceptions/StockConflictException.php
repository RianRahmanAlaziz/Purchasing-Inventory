<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class StockConflictException extends RuntimeException {}
