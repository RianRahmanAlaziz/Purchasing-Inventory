<?php

declare(strict_types=1);

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Rupiah, dibekukan sebagai snapshot saat PO diajukan.
    'gm_approval_threshold' => '10000000.00',

    // Basis approval = subtotal + pajak penambah harga.
    // Pajak potong tidak mengurangi nilai dasar keputusan.
    'approval_basis' => 'before_withholding',
];
