import test from "node:test";
import assert from "node:assert/strict";
import { decidePurchaseOrder } from "../utils/approval.ts";
import { submitPurchaseOrder, editPurchaseOrder, removePurchaseOrder } from "../../purchase-orders/utils/mutations.ts";

const draft = () => ({
  id: "PO-1", supplier: "Supplier", initials: "S", color: "blue", date: "2026-11-03", status: "Draft", amount: 10000, items: 1,
  companyId: "pt-a", company: { id: "pt-a", name: "PT A", address: "", phone: "", email: "", logo: "" }, paymentTerms: "Kontan",
  lines: [{ id: "item-1", description: "Kertas", quantity: 1, unit: "rim", unitPrice: 10000, discountPercent: 0, taxPercent: 0 }],
});

test("draft diajukan dan keputusan memperbarui PO yang sama tanpa mengubah data lain", () => {
  const original = draft();
  const other = { ...draft(), id: "PO-2", status: "Sent" };
  const pending = submitPurchaseOrder([original, other], original.id);
  assert.equal(pending[0].status, "Pending Approval");
  assert.ok(Number.isFinite(Date.parse(pending[0].submittedAt)));
  assert.equal(original.status, "Draft");
  assert.equal(pending[1], other);
  assert.throws(() => editPurchaseOrder(pending, original), /Hanya PO berstatus Draft/);
  assert.throws(() => removePurchaseOrder(pending, original.id), /Hanya PO berstatus/);
  const decided = decidePurchaseOrder(pending, original.id, "Approved", " Budi ", " Sesuai anggaran ");
  assert.equal(decided[0].id, original.id);
  assert.equal(decided[0].status, "Approved");
  assert.deepEqual(decided[0].lines, original.lines);
  assert.deepEqual(decided[0].company, original.company);
  assert.equal(decided[0].amount, original.amount);
  assert.equal(decided[0].approval.reviewer, "Budi");
  assert.equal(decided[0].approval.reason, "Sesuai anggaran");
  assert.equal(decided[0].approval.decision, "Approved");
  assert.ok(Number.isFinite(Date.parse(decided[0].approval.decidedAt)));
  assert.equal(pending[0].status, "Pending Approval");
  assert.equal(decided[1], other);
  assert.deepEqual(JSON.parse(JSON.stringify(decided)), decided);
});

test("setuju dan tidak setuju langsung tanpa isian pemeriksa atau alasan", () => {
  const pending = submitPurchaseOrder([draft()], "PO-1");
  for (const decision of ["Approved", "Rejected"]) {
    const result = decidePurchaseOrder(pending, "PO-1", decision);
    assert.equal(result[0].status, decision);
    assert.equal(result[0].approval.decision, decision);
    assert.equal(result[0].approval.reviewer, "");
    assert.equal(result[0].approval.reason, "");
    assert.ok(Number.isFinite(Date.parse(result[0].approval.decidedAt)));
    assert.throws(() => decidePurchaseOrder(result, "PO-1", decision), /Pending Approval/);
  }
  assert.throws(() => decidePurchaseOrder(pending, "PO-1", "Sent", "Budi", ""), /Keputusan tidak valid/);
  const rejected = decidePurchaseOrder(pending, "PO-1", "Rejected", "Budi", " Harga perlu direvisi ");
  assert.equal(rejected[0].status, "Rejected");
  assert.equal(rejected[0].approval.reason, "Harga perlu direvisi");
  assert.equal(rejected[0].approval.reviewer, "Budi");
});

test("keputusan ulang dan pengajuan dari status selain Draft ditolak", () => {
  for (const status of ["Draft", "Approved", "Rejected", "Sent", "Received by Supplier", "Cancelled"]) {
    assert.throws(() => decidePurchaseOrder([{ ...draft(), status }], "PO-1", "Approved", "Budi", ""), /Pending Approval/);
  }
  for (const status of ["Pending Approval", "Approved", "Rejected", "Sent", "Cancelled"]) {
    assert.throws(() => submitPurchaseOrder([{ ...draft(), status }], "PO-1"), /Hanya PO Draft/);
  }
  const decided = decidePurchaseOrder(submitPurchaseOrder([draft()], "PO-1"), "PO-1", "Approved", "Budi", "");
  assert.throws(() => decidePurchaseOrder(decided, "PO-1", "Rejected", "Siti", "Ubah keputusan"), /Pending Approval/);
});

test("PO hilang atau Draft belum lengkap tidak dapat diajukan", () => {
  assert.throws(() => submitPurchaseOrder([], "missing"), /tidak ditemukan/);
  assert.throws(() => decidePurchaseOrder([], "missing", "Approved", "Budi", ""), /tidak ditemukan/);
  assert.throws(() => submitPurchaseOrder([{ ...draft(), lines: [] }], "PO-1"), /minimal satu barang/);
  assert.throws(() => submitPurchaseOrder([{ ...draft(), company: undefined }], "PO-1"), /PT penerbit/);
});
