<?php

declare(strict_types=1);

namespace App\Domain\Category\Actions;

use App\Domain\Category\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveCategory
{
    public function create(array $data): Category
    {
        // Kategori baru otomatis aktif jika tidak ditentukan.
        // Nilai false yang dikirim pengguna tetap dihormati.
        $data['is_active'] ??= true;

        try {
            return DB::transaction(
                static fn (): Category => Category::query()->create($data),
                attempts: 3,
            );
        } catch (QueryException $exception) {
            $this->rethrowDuplicateAsValidation($exception);

            throw $exception;
        }
    }

    public function update(
        Category $category,
        array $data
    ): Category {
        try {
            return DB::transaction(
                function () use ($category, $data): Category {
                    $locked = Category::query()
                        ->lockForUpdate()
                        ->findOrFail($category->getKey());

                    $locked->update($data);

                    return $locked->refresh();
                },
                attempts: 3,
            );
        } catch (QueryException $exception) {
            $this->rethrowDuplicateAsValidation($exception);

            throw $exception;
        }
    }

    private function rethrowDuplicateAsValidation(
        QueryException $exception
    ): void {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw ValidationException::withMessages([
                'category' => [
                    'Kode atau nama kategori sudah digunakan.',
                ],
            ]);
        }
    }
}
