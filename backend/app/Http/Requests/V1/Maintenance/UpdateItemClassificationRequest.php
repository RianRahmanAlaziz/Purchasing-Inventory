<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Maintenance;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateItemClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && (
                $user->hasRole('super_admin', 'web')
                || $user->hasRole('hr', 'web')
            )
            && $user->can('maintenance.asset-items.classify');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge([
                'reason' => trim($this->input('reason')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'is_asset_trackable' => [
                'required',
                'boolean',
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
        ];
    }
}
