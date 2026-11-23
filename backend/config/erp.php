<?php

declare(strict_types=1);

return [
    'applications' => [
        'purchasing' => [
            'name' => 'Purchasing',
            'description' => 'Pengadaan dan purchase order.',
            'icon' => 'shopping-cart',
            'order' => 10,
            'enabled' => true,
            'permissions' => [
                'po.view',
                'po.create',
                'orders.view',
            ],
        ],

        'inventory' => [
            'name' => 'Inventory',
            'description' => 'Barang, stok, dan penerimaan.',
            'icon' => 'package',
            'order' => 20,
            'enabled' => true,
            'permissions' => [
                'items.view',
                'items.create',
                'inventory.view',
                'inventory.adjust',
                'grn.view',
                'grn.create',
            ],
        ],

        'distribution' => [
            'name' => 'Distribution',
            'description' => 'Permintaan dan distribusi barang.',
            'icon' => 'truck',
            'order' => 30,
            'enabled' => true,
            'permissions' => [
                'distribution.view',
                'distribution.create',
                'distribution.prepare',
                'distribution.send',
                'distribution.approve',
                'distribution.reject',
            ],
        ],

        'finance' => [
            'name' => 'Finance',
            'description' => 'Tagihan dan pembayaran.',
            'icon' => 'wallet',
            'order' => 40,
            'enabled' => true,
            'permissions' => [
                'invoices.view',
                'invoices.manage',
                'payments.view',
                'payments.manage',
                'payments.verify',
            ],
        ],

        'documents' => [
            'name' => 'Documents',
            'description' => 'Dokumen dan verifikasi.',
            'icon' => 'file-text',
            'order' => 50,
            'enabled' => true,
            'permissions' => [
                'documents.view',
                'documents.manage',
                'documents.send',
                'documents.receive',
                'documents.verify',
            ],
        ],

        'reporting' => [
            'name' => 'Reporting',
            'description' => 'Laporan operasional.',
            'icon' => 'chart-no-axes-combined',
            'order' => 60,
            'enabled' => true,
            'permissions' => [
                'reports.view',
            ],
        ],

        'administration' => [
            'name' => 'Administration',
            'description' => 'Pengguna dan hak akses.',
            'icon' => 'shield-check',
            'order' => 70,
            'enabled' => true,
            'permissions' => [
                'users.view',
                'users.manage',
                'roles.manage',
            ],
        ],
    ],
];
