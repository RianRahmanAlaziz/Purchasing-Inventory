import test from "node:test";
import assert from "node:assert/strict";
import { apiLine, poPayload, snapshotCompany } from "../utils/api-draft.ts";

const line = apiLine({ id: 5, item_id: 3, name: "Barang pada surat", unit: "pcs", quantity: "1.001",
  unit_price: "90071992547409.91", discount_percent: "12.3456", tax_rate_id: 7, tax_label: "PPN",
  tax_direction: "addition", tax_rate_percent: "12.0000", tax_base_factor: "0.91666667", notes: "Catatan barang" });
const input = () => ({ companyId: "1", supplierId: "2", employeeId: "4", departmentId: "5",
  supplierAddress: "Jalan satu\nJakarta", date: "2026-11-05", expectedDate: "", notes: "Baris satu\nBaris dua",
  lines: [line], taxScope: "line", orderRateIds: [] });

test("edit PO mempertahankan presisi desimal, deskripsi, satuan, catatan, dan ID tarif", () => {
  const body = poPayload(input());
  assert.equal(body.items[0].unit_price, "90071992547409.91");
  assert.equal(body.items[0].quantity, "1.001");
  assert.equal(body.items[0].discount_percent, "12.3456");
  assert.equal(body.items[0].description, "Barang pada surat");
  assert.equal(body.items[0].notes, "Catatan barang");
  assert.equal(body.items[0].tax_rate_id, 7);
  assert.equal(body.supplier_address, "Jalan satu\nJakarta");
  assert.equal(body.notes, "Baris satu\nBaris dua");
  assert.equal(body.expected_date, null);
  assert.equal(body.tax_scope, "line");
  assert.deepEqual(body.tax_rate_ids, []);
});

test("pajak seluruh PO lama tetap dikirim tanpa dikonversi menjadi pajak per barang", () => {
  const body = poPayload({ ...input(), taxScope: "order", orderRateIds: ["7", "8"] });
  assert.equal(body.tax_scope, "order");
  assert.deepEqual(body.tax_rate_ids, [7, 8]);
  assert.equal(body.items[0].tax_rate_id, null);
});

test("barang duplikat, ID kosong, eksponen harga, serta diskon tidak valid dicegah", () => {
  assert.throws(() => poPayload({ ...input(), lines: [line, { ...line, id: "second" }] }), /satu baris/);
  assert.throws(() => poPayload({ ...input(), companyId: "" }), /Pilih PT/);
  assert.throws(() => poPayload({ ...input(), lines: [{ ...line, unitPrice: "1e10" }] }), /Harga/);
  assert.throws(() => poPayload({ ...input(), lines: [{ ...line, discountPercent: "100.0001" }] }), /Diskon/);
  assert.throws(() => poPayload({ ...input(), taxScope: "order", orderRateIds: ["legacy"] }), /Pilih ulang pajak/);
});

test("kop surat berasal dari snapshot PO meski master perusahaan berubah", () => {
  assert.deepEqual(snapshotCompany({ id: 1, company_snapshot: { id: 9, name: "PT Lama", address: "Alamat lama" } }),
    { id: "9", name: "PT Lama", address: "Alamat lama", phone: "", email: "", logo: "" });
  assert.equal(snapshotCompany({ id: 1 }), undefined);
});

test("supplier dan barang baru dikirim dengan nama bebas serta kategori tanpa ID palsu", () => {
  const body = poPayload({ ...input(), supplierId: "", supplierName: "CV Baru",
    lines: [{ ...line, itemId: "", itemName: "Barang Baru", description: "Spesifikasi barang baru", categoryId: "9" }] });
  assert.equal(body.supplier_id, null);
  assert.equal(body.supplier_name, "CV Baru");
  assert.equal(body.items[0].item_id, null);
  assert.equal(body.items[0].name, "Barang Baru");
  assert.equal(body.items[0].category_id, 9);
  assert.equal(body.items[0].description, "Spesifikasi barang baru");
});

test("beberapa barang baru tidak dianggap duplikat hanya karena ID belum ada", () => {
  const body = poPayload({ ...input(), lines: [
    { ...line, itemId: "", itemName: "Barang A", categoryId: "9" },
    { ...line, id: "second", itemId: "", itemName: "Barang B", categoryId: "9" },
  ] });
  assert.equal(body.items.length, 2);
  assert.throws(() => poPayload({ ...input(), supplierId: "", supplierName: " " }), /nama supplier/);
});

