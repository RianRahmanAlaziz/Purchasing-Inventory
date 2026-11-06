import { workspaceToday } from "@/lib/export-period";
import { ITEM_UNITS } from "@/lib/item-units";
import { get, records, type ApiModule, type Column, type Field, type Operation, type OptionSource, type Row } from "../api-types";

const status: Column = { key: "status", label: "Status", format: "status" };
const active: Column = { key: "is_active", label: "Status", format: "status" };
const activeField: Field = { key: "is_active", label: "Aktif", type: "boolean" };
const name: Column = { key: "name", label: "Nama" };
const code: Column = { key: "code", label: "Kode" };
const email: Column = { key: "email", label: "Email" };
const notes: Column = { key: "notes", label: "Catatan" };
const nameField: Field = { ...name, required: true };
const codeField: Field = { ...code, required: true, maxLength: 40 };
const emailField: Field = { ...email, type: "email", maxLength: 190 };
const notesField: Field = { ...notes, type: "textarea", maxLength: 5000 };
const lineNotes: Field = { ...notesField, maxLength: 2000 };
const always = () => true;
const draft = (row: Row) => row.status === "draft";
const pending = (row: Row) => row.status === "pending";
const manage = (permission: string) => ({ create: permission, edit: permission, remove: permission });

function options(path: string, idLabel: string, nameLabel = "name"): OptionSource {
  return {
    path,
    options: rows => rows.map(row => ({
      value: String(row.id), label: [get(row, idLabel), get(row, nameLabel)].filter(Boolean).join(" · "),
    })),
  };
}

const employees = options("employees?is_active=1", "employee_number");
const departments = options("departments?is_active=1", "code");
const suppliers = options("suppliers?is_active=1", "code");
const items = options("items?is_active=1", "sku");
const categories = options("categories?is_active=1", "code");
const taxPercent = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 4 });

const itemField: Field = { key: "item_id", label: "Barang", type: "id", required: true, source: items };
const quantity: Field & Column = { key: "quantity", label: "Jumlah", type: "quantity", format: "quantity", required: true, min: 0.001 };
const unitPrice: Field = { key: "unit_price", label: "Harga satuan", type: "money", required: true };
const lineColumns: Column[] = [
  { key: "sku", label: "SKU" }, { key: "name", label: "Barang" }, { key: "unit", label: "Satuan" },
];

const purchaseOrders: ApiModule = {
  endpoint: "purchase-orders",
  title: "Purchase Order",
  description: "Buat draft, ajukan persetujuan, dan catat pengiriman ke supplier.",
  date: "order_date",
  status: "status",
  loadDetail: true,
  columns: [
    { key: "po_number", label: "No. PO" }, { key: "supplier_name_snapshot", label: "Supplier" },
    { key: "order_date", label: "Tanggal", format: "date" }, { key: "total_amount", label: "Total", format: "money" }, status,
  ],
  details: [
    { key: "company_snapshot.name", label: "PT penerbit" }, { key: "supplier_address_snapshot", label: "Alamat supplier" },
    { key: "expected_date", label: "Perkiraan datang", format: "date" }, notes,
    { key: "subtotal", label: "Subtotal", format: "money" },
    { key: "discount_total", label: "Diskon", format: "money" },
    { key: "tax_addition_total", label: "Pajak tambahan", format: "money" },
    { key: "tax_withholding_total", label: "Potongan pajak", format: "money" },
  ],
  groups: [
    { key: "items", title: "Rincian barang", columns: [...lineColumns, quantity, { key: unitPrice.key, label: unitPrice.label, format: "money" }, notes] },
    { key: "approvals", title: "Tahapan persetujuan", columns: [
      { key: "decided_by_name", label: "Nama pemeriksa" }, status,
      { key: "decided_at", label: "Waktu", format: "datetime" }, { key: "rejection_reason", label: "Alasan" },
    ] },
  ],
  fields: [
    { key: "supplier_id", label: "Supplier", type: "id", required: true, source: suppliers },
    { key: "requested_for_employee_id", label: "Karyawan pemohon", type: "id", required: true, source: employees },
    { key: "requested_for_department_id", label: "Departemen pemohon", type: "id", required: true, source: departments },
    { key: "order_date", label: "Tanggal pemesanan", type: "date", required: true, initial: workspaceToday },
    { key: "expected_date", label: "Perkiraan datang", type: "date" },
    { key: "tax_rate_ids", label: "Pajak yang berlaku", type: "multiple",
      from: row => records(row, "taxes").map(tax => String(tax.tax_rate_id)),
      source: {
        path: values => values.order_date ? "taxes/options?date=" + encodeURIComponent(String(values.order_date)) : null,
        options: rows => rows.map(row => ({
          value: String(row.id),
          label: row.label + " · " + taxPercent.format(Number(row.rate_percent)) + "%",
        })),
      },
    },
    notesField,
  ],
  lines: { fields: [itemField, quantity, unitPrice, lineNotes], limit: 100 },
  mutations: { create: "po.create", edit: "po.update", remove: "po.update" },
  edit: draft,
  remove: row => ["draft", "rejected", "cancelled"].includes(String(row.status)),
  operations: row => {
    const actions: Operation[] = [];
    if (row.status === "draft") actions.push({ label: "Ajukan persetujuan", suffix: "submit", permission: "po.submit" });
    if (row.status === "approved") actions.push({ label: "Catat pengiriman ke supplier", suffix: "send", permission: "po.send" });
    if (row.status === "sent") actions.push({ label: "Konfirmasi penerimaan supplier", suffix: "supplier-confirm", permission: "po.supplier-confirm" });
    if (["draft", "pending_approval", "approved"].includes(String(row.status))) actions.push({ label: "Batalkan PO", suffix: "cancel", reason: true, permission: "po.update" });
    return actions;
  },
  print: true,
};

export const apiModules: Record<string, ApiModule> = {
  "tax-types": {
    endpoint: "tax-types", title: "Jenis Pajak", description: "Kelola jenis pajak tambahan dan potongan.",
    mutations: manage("taxes.manage"), date: "created_at", status: "is_active",
    columns: [code, name, { key: "direction", label: "Jenis", format: "status" }, active],
    details: [{ key: "description", label: "Deskripsi" }],
    fields: [codeField, nameField, { key: "direction", label: "Jenis", required: true, choices: [
      { value: "addition", label: "Pajak tambahan" }, { value: "withholding", label: "Pajak potongan" },
    ] }, { key: "description", label: "Deskripsi", type: "textarea", maxLength: 5000 }, activeField],
    edit: always, remove: always,
  },
  "tax-rates": {
    endpoint: "tax-rates", title: "Tarif Pajak", description: "Kelola tarif dan masa berlaku pajak yang dipilih pada PO.",
    mutations: manage("taxes.manage"), date: "effective_from", status: "is_active",
    columns: [{ key: "label", label: "Tarif" }, { key: "tax_type.name", label: "Jenis pajak" },
      { key: "rate_percent", label: "Persentase (%)" }, { key: "taxable_base_factor", label: "Faktor dasar" },
      { key: "effective_from", label: "Mulai berlaku", format: "date" }, { key: "effective_until", label: "Berlaku hingga", format: "date" }, active],
    fields: [{ key: "tax_type_id", label: "Jenis pajak", type: "id", required: true, createOnly: true, source: options("tax-types?is_active=1", "code") },
      { key: "label", label: "Nama tarif", required: true, maxLength: 160 },
      { key: "rate_percent", label: "Persentase (%)", required: true, initial: "0" },
      { key: "taxable_base_factor", label: "Faktor dasar pajak", required: true, initial: "1" },
      { key: "effective_from", label: "Mulai berlaku", type: "date", required: true, initial: workspaceToday },
      { key: "effective_until", label: "Berlaku hingga", type: "date" }, activeField],
    edit: always, remove: always,
  },
  invoices: {
    endpoint: "invoices", title: "Tagihan Supplier", description: "Catat tagihan dan pantau pelunasan dari pembayaran yang diverifikasi.",
    mutations: manage("invoices.manage"), date: "invoice_date", status: "status", loadDetail: true,
    columns: [{ key: "invoice_number", label: "No. Tagihan" }, { key: "supplier_name", label: "Supplier" },
      { key: "total_amount", label: "Total tagihan", format: "money" }, { key: "paid_amount", label: "Dibayar", format: "money" }, status],
    details: [{ key: "po_number", label: "No. PO" }, { key: "invoice_date", label: "Tanggal", format: "date" },
      { key: "due_date", label: "Jatuh tempo", format: "date" }, { key: "pending_amount", label: "Menunggu verifikasi", format: "money" },
      { key: "remaining_amount", label: "Sisa tagihan", format: "money" }, notes],
    fields: [{ key: "invoice_number", label: "No. Tagihan", required: true, maxLength: 100 },
      { key: "supplier_id", label: "Supplier", type: "id", required: true, source: suppliers },
      { key: "purchase_order_id", label: "Purchase Order (opsional)", type: "id", source: {
        path: values => values.supplier_id ? "purchase-orders?supplier_id=" + values.supplier_id : null,
        options: rows => rows.filter(row => !["rejected", "cancelled"].includes(String(row.status))).map(row => ({ value: String(row.id), label: String(row.po_number) })),
      } }, { key: "invoice_date", label: "Tanggal tagihan", type: "date", required: true, initial: workspaceToday },
      { key: "due_date", label: "Jatuh tempo", type: "date" },
      { key: "total_amount", label: "Total tagihan", type: "money", min: 0.01, required: true }, notesField],
    edit: always, remove: row => row.can_delete === true,
  },
  payments: {
    endpoint: "payments", title: "Pembayaran", description: "Catat pembayaran tagihan dan verifikasi penerimaannya.",
    mutations: manage("payments.manage"), date: "payment_date", status: "status", loadDetail: true,
    columns: [{ key: "payment_number", label: "No. Pembayaran" }, { key: "invoice_number", label: "Tagihan" },
      { key: "supplier_name", label: "Supplier" }, { key: "amount", label: "Nominal", format: "money" }, status],
    details: [{ key: "payment_date", label: "Tanggal pembayaran", format: "date" }, { key: "po_number", label: "No. PO" },
      { key: "reference", label: "Referensi" }, notes, { key: "verified_at", label: "Diputuskan", format: "datetime" }, { key: "rejection_reason", label: "Alasan penolakan" }],
    fields: [{ key: "invoice_id", label: "Tagihan", type: "id", required: true, createOnly: true, source: {
      path: "invoices", options: rows => rows.filter(row => Number(row.available_amount) > 0)
        .map(row => ({ value: String(row.id), label: row.invoice_number + " · " + row.supplier_name + " · sisa " + row.available_amount })),
    } }, { key: "payment_date", label: "Tanggal pembayaran", type: "date", required: true, initial: workspaceToday },
      { key: "amount", label: "Nominal", type: "money", min: 0.01, required: true },
      { key: "reference", label: "Referensi transfer", maxLength: 150 }, notesField],
    edit: row => row.status === "pending_verification", remove: row => row.status === "pending_verification",
    operations: row => row.status === "pending_verification" ? [
      { label: "Verifikasi pembayaran", suffix: "verify", permission: "payments.verify" },
      { label: "Tolak pembayaran", suffix: "reject", reason: true, permission: "payments.verify" },
    ] : [],
  },
  receipts: {
    endpoint: "documents", title: "Kuitansi & Dokumen", description: "Simpan berkas privat dan catat pengiriman, penerimaan, serta verifikasi dokumen.",
    mutations: manage("documents.manage"), date: "document_date", status: "status", loadDetail: true,
    columns: [{ key: "document_number", label: "No. Dokumen" }, { key: "document_type", label: "Jenis dokumen" },
      { key: "supplier_name", label: "Supplier" }, { key: "document_date", label: "Tanggal", format: "date" }, status],
    details: [{ key: "po_number", label: "No. PO" }, { key: "file_name", label: "Berkas" }, notes,
      { key: "sent_at", label: "Dikirim", format: "datetime" }, { key: "received_at", label: "Diterima", format: "datetime" },
      { key: "verified_at", label: "Diverifikasi", format: "datetime" }],
    fields: [{ key: "document_type", label: "Jenis dokumen", required: true, maxLength: 150 },
      { key: "supplier_id", label: "Supplier", type: "id", required: true, source: suppliers },
      { key: "purchase_order_id", label: "Purchase Order (opsional)", type: "id", source: {
        path: values => values.supplier_id ? "purchase-orders?supplier_id=" + values.supplier_id : null,
        options: rows => rows.map(row => ({ value: String(row.id), label: String(row.po_number) })),
      } }, { key: "document_date", label: "Tanggal dokumen", type: "date", required: true, initial: workspaceToday },
      { key: "file", label: "Berkas", type: "file", accept: ".pdf,.png,.jpg,.jpeg,.webp", omitEmpty: true }, notesField],
    edit: row => ["not_uploaded", "uploaded"].includes(String(row.status)), remove: row => ["not_uploaded", "uploaded"].includes(String(row.status)),
    operations: row => row.status === "uploaded" ? [{ label: "Kirim dokumen", suffix: "send", permission: "documents.send" }]
      : row.status === "sent" ? [{ label: "Catat penerimaan dokumen", suffix: "receive", permission: "documents.receive" }]
      : row.status === "received" ? [{ label: "Verifikasi dokumen", suffix: "verify", permission: "documents.verify" }] : [],
  },
  suppliers: {
    endpoint: "suppliers", title: "Supplier", description: "Kelola mitra pengadaan dan informasi kontaknya.",
    mutations: { create: "suppliers.create", edit: "suppliers.update", remove: "suppliers.delete" },
    columns: [code, name, { key: "contact_person", label: "Kontak" }, email, active], date: "created_at", status: "is_active",
    details: [{ key: "phone", label: "Telepon" }, { key: "address", label: "Alamat" }, notes],
    fields: [codeField, nameField, { key: "contact_person", label: "Kontak", maxLength: 150 },
      { key: "phone", label: "Telepon", maxLength: 35 }, emailField, { key: "address", label: "Alamat", type: "textarea", maxLength: 5000 }, notesField, activeField],
    edit: always, remove: always,
  },
  categories: {
    endpoint: "categories", title: "Kategori", description: "Kelola kategori untuk master barang.",
    mutations: manage("categories.manage"),
    columns: [code, name, { key: "description", label: "Deskripsi" }, active], date: "created_at", status: "is_active",
    fields: [codeField, nameField, { key: "description", label: "Deskripsi", type: "textarea", maxLength: 5000 }, activeField],
    edit: always, remove: always,
  },
  departments: {
    endpoint: "departments", title: "Departemen", description: "Kelola departemen yang digunakan pada data karyawan dan PO.",
    mutations: manage("departments.manage"),
    columns: [code, name, active], date: "created_at", status: "is_active",
    fields: [codeField, nameField, activeField], edit: always, remove: always,
  },
  employees: {
    endpoint: "employees", title: "Karyawan", description: "Kelola karyawan, departemen, dan tautan ke akun Laravel yang sudah ada.",
    mutations: manage("employees.manage"),
    columns: [{ key: "employee_number", label: "Nomor karyawan" }, name, { key: "department.name", label: "Departemen" }, email, active],
    details: [{ key: "user.email", label: "Akun Laravel" }], date: "created_at", status: "is_active",
    fields: [
      { key: "employee_number", label: "Nomor karyawan", required: true, maxLength: 60 }, { ...nameField, maxLength: 160 },
      { key: "department_id", from: "department.id", label: "Departemen", type: "id", source: departments }, emailField,
      { key: "user_id", from: "user.id", label: "ID akun Laravel (opsional)", type: "id" }, activeField,
    ],
    edit: always, remove: always,
  },
  items: {
    endpoint: "items", title: "Stok Barang", description: "Kelola master barang, pantau saldo, dan catat penyesuaian stok.",
    mutations: manage("items.manage"),
    columns: [{ key: "sku", label: "SKU" }, name, { key: "category.name", label: "Kategori" },
      { key: "stock_quantity", label: "Stok", format: "quantity" }, { key: "minimum_stock", label: "Stok minimum", format: "quantity" }, { key: "unit", label: "Satuan" },
      { key: "last_purchase_price", label: "Harga terakhir", format: "money" }, { key: "is_low_stock", label: "Ketersediaan", format: "status" }, active],
    date: "created_at", status: "is_low_stock",
    details: [{ key: "id", label: "ID barang" }],
    fields: [{ key: "sku", label: "SKU", required: true }, { ...nameField, maxLength: 200 },
      { key: "category_id", from: "category.id", label: "Kategori", type: "id", required: true, source: categories },
      { key: "unit", label: "Satuan", required: true, initial: "pcs", choices: ITEM_UNITS.map(unit => ({ value: unit, label: unit })) },
      { key: "minimum_stock", label: "Stok minimum", type: "quantity", required: true, initial: "0" }, activeField],
    edit: always, remove: always,
    operations: () => [{ label: "Sesuaikan stok", suffix: "stock-adjustments", adjustment: true, permission: "inventory.adjust" }],
  },
  "purchase-orders": purchaseOrders,
  approvals: {
    ...purchaseOrders,
    title: "Persetujuan PO",
    description: "Periksa dan putuskan PO pada tahap HR atau GM sesuai hak akses.",
    filter: row => records(row, "approvals").length > 0,
    fields: undefined, edit: undefined, remove: undefined, mutations: undefined,
    operations: (row, user) => {
      if (row.status !== "pending_approval" || row.created_by === user.id) return [];
      const approvals = records(row, "approvals");
      const next = approvals.find(step => step.status === "pending");
      if (!next || !["hr", "general_manager"].includes(String(next.step))) return [];
      const step = next.step === "general_manager" ? "gm" : "hr";
      if (step === "gm" && approvals.some(approval => approval.step === "hr" && approval.decided_by === user.id)) return [];
      const prefix = "approvals/" + step;
      return [
        { label: "Setujui " + step.toUpperCase(), suffix: prefix + "/approve", permission: "po.approve." + step },
        { label: "Tolak " + step.toUpperCase(), suffix: prefix + "/reject", reason: true, permission: "po.reject." + step },
      ];
    },
  },
  orders: {
    endpoint: "orders", title: "Order Management", description: "Pantau order yang terbentuk setelah PO disetujui.",
    columns: [{ key: "purchase_order.po_number", label: "No. PO" }, { key: "purchase_order.supplier_name", label: "Supplier" },
      { key: "purchase_order.total_amount", label: "Total", format: "money" }, { key: "created_at", label: "Dibuat", format: "date" }, status],
    date: "created_at", status: "status",
    loadDetail: true,
    details: [{ key: "confirmed_at", label: "Dikonfirmasi", format: "datetime" }, { key: "completed_at", label: "Selesai", format: "datetime" }],
    groups: [{ key: "receiving_progress.items", title: "Progres penerimaan", columns: [...lineColumns,
      { key: "ordered_quantity", label: "Dipesan", format: "quantity" }, { key: "usable_received_quantity", label: "Diterima baik", format: "quantity" }, { key: "remaining_quantity", label: "Sisa", format: "quantity" }] }],
  },
  "goods-receipts": {
    endpoint: "goods-receipts", title: "Penerimaan Barang", description: "Catat barang yang datang. Konfirmasi penerimaan untuk memperbarui stok.",
    mutations: { create: "grn.create", edit: "grn.create" },
    columns: [{ key: "grn_number", label: "No. Penerimaan" }, { key: "po_number", label: "No. PO" },
      { key: "received_date", label: "Tanggal terima", format: "date" }, status],
    date: "received_date", status: "status", details: [notes, { key: "confirmed_at", label: "Dikonfirmasi", format: "datetime" }],
    groups: [{ key: "items", title: "Rincian barang", columns: [...lineColumns,
      { key: "qty_received", label: "Diterima", format: "quantity" }, { key: "qty_damaged", label: "Rusak", format: "quantity" }, notes] }],
    fields: [
      { key: "purchase_order_id", label: "Purchase Order", type: "id", required: true, createOnly: true, resetLines: true, source: {
        path: "purchase-orders",
        options: rows => rows.filter(row => ["sent", "received_by_supplier"].includes(String(row.status)))
          .map(row => ({ value: String(row.id), label: row.po_number + " · " + row.supplier_name_snapshot })),
      } },
      { key: "received_date", label: "Tanggal penerimaan", type: "date", required: true, initial: workspaceToday }, notesField,
    ],
    lines: { limit: 100, fields: [
      { key: "purchase_order_item_id", label: "Barang pada PO", type: "id", required: true, source: {
        single: true,
        path: (values, row) => {
          const id = row?.purchase_order_id ?? values.purchase_order_id;
          return /^[1-9][0-9]*$/.test(String(id)) ? "purchase-orders/" + id : null;
        },
        options: rows => records(rows[0], "items").map(line => ({ value: String(line.id), label: line.sku + " · " + line.name })),
      } },
      { key: "qty_received", label: "Jumlah diterima", type: "quantity", required: true, min: 0.001 },
      { key: "qty_damaged", label: "Jumlah rusak", type: "quantity", required: true, initial: "0" }, lineNotes,
    ] },
    edit: pending,
    operations: row => pending(row) ? [{ label: "Konfirmasi penerimaan", suffix: "confirm", permission: "grn.confirm" }] : [],
  },
  distributions: {
    endpoint: "distributions", title: "Distribusi Barang", description: "Ajukan permintaan barang, proses pengiriman, dan konfirmasi penerimaan.",
    mutations: { create: user => user.employee_id !== null || user.permissions.includes("distribution.create") },
    columns: [{ key: "distribution_number", label: "No. Distribusi" }, { key: "employee.name", label: "Penerima" },
      { key: "requested_at", label: "Tanggal permintaan", format: "date" }, status],
    date: "requested_at", status: "status",
    details: [{ key: "purpose", label: "Keperluan" }, { key: "rejection_reason", label: "Alasan penolakan" }, { key: "received_at", label: "Diterima", format: "datetime" }],
    fields: [
      { key: "employee_id", label: "Penerima (kosongkan untuk diri sendiri)", type: "id", source: employees, omitEmpty: true },
      { key: "purpose", label: "Keperluan", type: "textarea", required: true, maxLength: 5000 },
    ],
    lines: { limit: 50, fields: [itemField, quantity, lineNotes] },
    groups: [{ key: "items", title: "Rincian barang", columns: [...lineColumns, quantity, notes] }],
    operations: (row, user) => {
      if (row.status === "sent" && row.employee_id === user.employee_id) return [{ label: "Konfirmasi barang diterima", suffix: "receive" }];
      switch (row.status) {
        case "requested": return row.requested_by === user.id ? [] : [{ label: "Setujui permintaan", suffix: "approve", permission: "distribution.approve" },
          { label: "Tolak permintaan", suffix: "reject", reason: true, permission: "distribution.reject" }];
        case "approved": return [{ label: "Mulai persiapan", suffix: "prepare", permission: "distribution.prepare" }];
        case "preparing": return [{ label: "Kirim barang", suffix: "send", permission: "distribution.send" }];
        default: return [];
      }
    },
  },
  "stock-movements": {
    endpoint: "stock-movements", title: "Mutasi Stok", description: "Riwayat penerimaan, distribusi, dan penyesuaian stok yang tercatat di Laravel.",
    columns: [{ key: "created_at", label: "Waktu", format: "datetime" }, { key: "item.sku", label: "SKU" }, { key: "item.name", label: "Barang" },
      { key: "movement_type", label: "Jenis mutasi", format: "status" }, { key: "quantity_change", label: "Perubahan", format: "quantity" },
      { key: "balance_before", label: "Saldo awal", format: "quantity" }, { key: "balance_after", label: "Saldo akhir", format: "quantity" }],
    details: [{ key: "item.unit", label: "Satuan" }, { key: "reason", label: "Alasan" },
      { key: "goods_receipt_item_id", label: "ID baris penerimaan" }, { key: "distribution_item_id", label: "ID baris distribusi" },
      { key: "stock_adjustment_id", label: "ID penyesuaian" }],
    date: "created_at", status: "movement_type",
  },
};
