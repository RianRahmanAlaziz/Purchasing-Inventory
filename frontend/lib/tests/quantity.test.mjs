import test from "node:test";
import assert from "node:assert/strict";
import { formatQuantity, quantityInput } from "../quantity.ts";

test("tiga unit dibedakan dari tiga ribu dengan format Indonesia", () => {
  assert.equal(formatQuantity("3.000"), "3");
  assert.equal(formatQuantity("3000.000"), "3.000");
  assert.equal(formatQuantity("3000.500"), "3.000,5");
  assert.equal(formatQuantity("0.001"), "0,001");
});

test("mutasi negatif dan angka stok besar mempertahankan nilai tepatnya", () => {
  assert.equal(formatQuantity("-3.500"), "-3,5");
  assert.equal(formatQuantity("999999999999999.999"), "999.999.999.999.999,999");
  assert.equal(formatQuantity("-0.000"), "0");
  assert.equal(formatQuantity(0), "0");
});

test("nilai kosong atau tidak valid tidak ditampilkan sebagai stok nol", () => {
  for (const value of [null, undefined, "", "abc", NaN]) assert.equal(formatQuantity(value), "—");
});

test("isian jumlah menghilangkan nol pecahan tanpa mengubah nilai yang dikirim ke API", () => {
  assert.equal(quantityInput("3.000"), "3");
  assert.equal(quantityInput("3000.000"), "3000");
  assert.equal(quantityInput("3.500"), "3.5");
  assert.equal(quantityInput("1.001"), "1.001");
  assert.equal(quantityInput("999999999999999.999"), "999999999999999.999");
});
