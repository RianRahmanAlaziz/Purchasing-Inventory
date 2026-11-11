<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\TaxReferenceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ImportRefnusaPpn
{
    private const ENDPOINT = 'https://refnusa.dev/v1/tax-rates';

    private const REGULATION_PDF = 'https://jdih.kemenkeu.go.id/api/download/ad276b82-94bd-4197-b409-af33e2842cd6/2024pmkeuangan131.pdf';

    public function __construct(private readonly SaveOfficePpn $saveOfficePpn) {}

    /** @return array{imported: int, skipped: int} */
    public function execute(): array
    {
        $rates = $this->fetchRates();
        $this->validatePpnRate($rates, 'ppn_rate', 12);
        $this->validatePpnRate($rates, 'ppn_non_mewah_effective', 11);
        $created = $this->saveOfficePpn->execute();

        return ['imported' => (int) $created, 'skipped' => (int) ! $created];
    }

    private function fetchRates(): array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->get(self::ENDPOINT, ['category' => 'ppn']);
        } catch (ConnectionException $exception) {
            throw new TaxReferenceUnavailable('Refnusa tidak dapat dihubungi. Coba lagi.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new TaxReferenceUnavailable('Refnusa belum dapat menyediakan data PPN. Coba lagi.');
        }

        $rates = $response->json('rates');
        if (! is_array($rates)) {
            throw new TaxReferenceUnavailable('Format data tarif PPN dari Refnusa tidak dikenali.');
        }

        return $rates;
    }

    private function validatePpnRate(array $rates, string $code, int $expectedPercent): void
    {
        $matches = collect($rates)->whereStrict('rate_code', $code)->values()->all();
        // The stored rate and DPP factor apply to PMK 131/2024.
        $validator = Validator::make(['rows' => $matches], [
            'rows' => ['required', 'array', 'size:1'],
            'rows.0.category' => ['required', Rule::in(['ppn'])],
            'rows.0.unit' => ['required', Rule::in(['percent'])],
            'rows.0.value' => ['required', 'numeric', Rule::in([$expectedPercent])],
            'rows.0.effective_date' => ['required', Rule::in([SaveOfficePpn::EFFECTIVE_FROM])],
            'rows.0.source_url' => ['required', Rule::in([self::REGULATION_PDF])],
        ]);
        if ($validator->fails()) {
            throw new TaxReferenceUnavailable('Format atau rujukan tarif PPN dari Refnusa berubah. Periksa data sumber.');
        }
    }
}
