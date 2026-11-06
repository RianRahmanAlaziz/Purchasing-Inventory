<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'address' => $this->address ?? '',
            'phone' => $this->phone ?? '', 'email' => $this->email ?? '', 'logo' => $this->logo ?? ''];
    }
}
