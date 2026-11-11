<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tax\Actions\SaveOfficePpn;
use Illuminate\Database\Seeder;

final class PpnIndonesia2026Seeder extends Seeder
{
    public function run(SaveOfficePpn $saveOfficePpn): void
    {
        $created = $saveOfficePpn->execute(active: true);

        $this->command?->info($created ? 'Tarif PPN Nonmewah ditambahkan.' : 'Tarif PPN Nonmewah sudah tersedia.');
    }
}
