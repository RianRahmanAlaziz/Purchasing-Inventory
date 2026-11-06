import test from "node:test";
import assert from "node:assert/strict";
import { lineTotals, orderTotals } from "../utils/totals.ts";

const line = (values = {}) => ({ id: "test", description: "Barang", quantity: 1, unit: "pcs", unitPrice: 100, discountPercent: 0, taxPercent: 0, ...values });

test("total sesuai dua barang pada surat PO P00115", () => {
  const totals = orderTotals([line({ quantity: 2, unitPrice: 12000 }), line({ quantity: 2, unitPrice: 15879 })]);
  assert.equal(totals.total, 55758);
  assert.equal(totals.discount, 0);
  assert.equal(totals.tax, 0);
});

test("diskon diterapkan sebelum pajak per barang", () => {
  const amount = lineTotals(line({ quantity: 2, unitPrice: 10000, discountPercent: 10, taxPercent: 11 }));
  assert.equal(amount.discount, 2000);
  assert.equal(amount.net, 18000);
  assert.equal(amount.tax, 1980);
  assert.equal(amount.total, 19980);
});

test("jumlah pecahan dan harga desimal dibulatkan ke dua angka", () => {
  assert.equal(lineTotals(line({ quantity: 1.5, unitPrice: 15879.25 })).total, 23818.88);
  assert.equal(orderTotals([line({ quantity: 3, unitPrice: .1 }), line({ unitPrice: .2 })]).total, .5);
});

test("diskon penuh menghasilkan total nol, termasuk pajak", () => {
  assert.equal(lineTotals(line({ discountPercent: 100, taxPercent: 11 })).total, 0);
});

test("daftar barang kosong tidak menghasilkan tagihan", () => {
  assert.deepEqual(orderTotals([]), { subtotal: 0, discount: 0, tax: 0, total: 0 });
});

test("tarif database memakai faktor dasar pajak setelah diskon", () => {
  const amount = lineTotals(line({
    quantity: 2, unitPrice: 10000, discountPercent: 10, taxPercent: 12,
    taxRate: { id: 1, label: "Tarif uji", direction: "addition", baseFactor: 0.5 },
  }));
  assert.equal(amount.tax, 1080);
  assert.equal(amount.total, 19080);
});

test("tarif pemotong dari database mengurangi total", () => {
  const amount = lineTotals(line({
    quantity: 2, unitPrice: 10000, discountPercent: 10, taxPercent: 2,
    taxRate: { id: 2, label: "Pemotongan uji", direction: "withholding", baseFactor: 1 },
  }));
  assert.equal(amount.tax, -360);
  assert.equal(amount.total, 17640);
});
