<?php

declare(strict_types=1);

return [
    // Rupiah, dibekukan sebagai snapshot saat PO diajukan.
    'gm_approval_threshold' => '7000000.00',

    // Basis approval = subtotal + pajak penambah harga.
    // Pajak potong tidak mengurangi nilai dasar keputusan.
    'approval_basis' => 'before_withholding',
];
