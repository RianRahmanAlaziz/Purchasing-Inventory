<?php

declare(strict_types=1);

namespace App\Domain\Category\Actions;

use App\Domain\Category\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteCategory
{
    public function execute(Category $category): bool
    {
        try {
            return DB::transaction(function () use ($category): bool {
                $locked = Category::query()
                    ->lockForUpdate()
                    ->findOrFail($category->getKey());

                if (DB::table('items')->where('category_id', $locked->getKey())->exists()) {
                    return false;
                }

                return (bool) $locked->delete();
            }, attempts: 3);
        } catch (QueryException $exception) {
            // MySQL 1451: foreign key mencegah penghapusan parent.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                return false;
            }

            throw $exception;
        }
    }
}
