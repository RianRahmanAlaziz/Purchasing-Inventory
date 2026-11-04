import test from "node:test";
import assert from "node:assert/strict";
import { canEditPurchaseOrder, canDeletePurchaseOrder, insertPurchaseOrder, editPurchaseOrder, removePurchaseOrder } from "../utils/mutations.ts";

const company = { id: "pt-a", name: "PT A", address: "Alamat A", phone: "021", email: "a@example.test", logo: "" };
const draft = (changes = {}) => ({
  id: "PO-2026-0050", supplier: "Supplier A", initials: "SA", color: "blue", date: "2026-11-03", expectedDate: "2026-11-04", paymentTerms: "Kontan",
  status: "Draft", companyId: company.id, company: { ...company }, amount: 0, items: 0,
  lines: [{ id: "line-1", description: "Kertas", quantity: 2, unit: "rim", unitPrice: 10000, discountPercent: 10, taxPercent: 11 }],
  ...changes,
});

test("tambah, baca, edit, dan hapus tanpa mengubah order lain", () => {
  const existing = draft({ id: "PO-2026-0047", status: "Approved" });
  const created = insertPurchaseOrder([existing], draft());
  assert.equal(created.length, 2);
  assert.equal(created[0].amount, 19980);
  assert.equal(created[0].items, 1);
  assert.equal(created[1], existing);
  const changed = editPurchaseOrder(created, { ...created[0], supplier: "Supplier Baru", lines: [{ ...created[0].lines[0], quantity: 3 }] });
  assert.equal(changed[0].supplier, "Supplier Baru");
  assert.equal(changed[0].amount, 29970);
  assert.equal(changed[0].id, created[0].id);
  assert.equal(created[0].supplier, "Supplier A");
  assert.deepEqual(changed[0].company, company);
  assert.deepEqual(removePurchaseOrder(changed, changed[0].id), [existing]);
});

test("edit tidak dapat melewati status PO yang tersimpan", () => {
  for (const status of ["Pending Approval", "Approved", "Sent", "Received by Supplier", "Rejected", "Cancelled"]) {
    assert.equal(canEditPurchaseOrder(status), false);
    assert.throws(() => editPurchaseOrder([draft({ status })], draft()), /Hanya PO berstatus Draft/);
  }
  const result = editPurchaseOrder([draft()], draft({ status: "Approved" }));
  assert.equal(result[0].status, "Draft");
});

test("hapus dibatasi untuk Draft, Rejected, dan Cancelled", () => {
  for (const status of ["Draft", "Rejected", "Cancelled"]) {
    assert.equal(canDeletePurchaseOrder(status), true);
    assert.deepEqual(removePurchaseOrder([draft({ status })], "PO-2026-0050"), []);
  }
  for (const status of ["Pending Approval", "Approved", "Sent", "Received by Supplier"]) {
    assert.equal(canDeletePurchaseOrder(status), false);
    assert.throws(() => removePurchaseOrder([draft({ status })], "PO-2026-0050"), /Hanya PO berstatus/);
  }
});

test("nomor duplikat dan PO yang hilang ditolak", () => {
  assert.throws(() => insertPurchaseOrder([draft()], draft()), /sudah digunakan/);
  assert.throws(() => editPurchaseOrder([], draft()), /tidak ditemukan/);
  assert.throws(() => removePurchaseOrder([], "missing"), /tidak ditemukan/);
});

test("PO invalid tidak dapat disimpan melalui operasi data", () => {
  const invalidOrders = [draft({ lines: [] }), draft({ company: undefined }), draft({ companyId: "pt-lain" }), draft({ supplier: " " }), draft({ expectedDate: "2026-11-01" }), draft({ paymentTerms: "" }), draft({ lines: [{ ...draft().lines[0], quantity: NaN }] }), draft({ lines: [{ ...draft().lines[0], taxPercent: 101 }] })];
  for (const invalid of invalidOrders) {
    assert.throws(() => insertPurchaseOrder([], invalid));
    assert.throws(() => editPurchaseOrder([draft()], invalid));
  }
});

test("data tetap dapat dibaca setelah serialisasi penyimpanan browser", () => {
  const saved = insertPurchaseOrder([], draft());
  assert.deepEqual(JSON.parse(JSON.stringify(saved)), saved);
  assert.deepEqual(JSON.parse(JSON.stringify(removePurchaseOrder(saved, saved[0].id))), []);
});
