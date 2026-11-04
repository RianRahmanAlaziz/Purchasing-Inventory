import test from "node:test";
import assert from "node:assert/strict";
import { deleteItem, readItems, saveItem, stockStatus } from "../utils/items.ts";
import { workspaceToday, filterExportRecords } from "../../../lib/export-period.ts";

const item = { id: "item-1", sku: "ATK-001", name: "Kertas", category: "ATK", unit: "rim", stock: 8, minimum: 20, lastPrice: 50000, icon: "box", color: "blue", createdAt: "2026-11-03" };

test("tambah, simpan ulang, edit SKU dengan ID tetap, lalu hapus tanpa mengubah barang lain", () => {
  const original = [item];
  const created = saveItem(original, { ...item, id: "item-2", sku: " atk-002 ", name: " Tinta ", stock: 30 }, false);
  assert.equal(original.length, 1);
  assert.equal(created[1].sku, "ATK-002");
  assert.equal(created[1].name, "Tinta");
  assert.equal(created[1].createdAt, workspaceToday());
  const restored = readItems(JSON.stringify(created));
  assert.deepEqual(restored, created);
  const edited = saveItem(restored, { ...restored[1], sku: "ATK-003", stock: 0, lastPrice: 70000, createdAt: "2000-01-01" }, true);
  assert.equal(edited[1].id, "item-2");
  assert.equal(edited[1].createdAt, restored[1].createdAt);
  assert.equal(edited[1].lastPrice, 70000);
  assert.equal(edited[0], restored[0]);
  assert.equal(restored[1].stock, 30);
  assert.deepEqual(deleteItem(edited, "item-2"), [item]);
  assert.deepEqual(readItems(JSON.stringify(deleteItem([item], item.id))), []);
});

test("SKU unik saat tambah atau edit, terlepas dari huruf kapital", () => {
  assert.throws(() => saveItem([item], { ...item, id: "item-2", sku: "atk-001" }, false), /SKU sudah digunakan/);
  const other = { ...item, id: "item-2", sku: "ATK-002" };
  assert.throws(() => saveItem([item, other], { ...other, sku: "atk-001" }, true), /SKU sudah digunakan/);
  assert.equal(saveItem([item], item, true).length, 1);
});

test("isian kosong, angka negatif, NaN, tak hingga, dan batas maksimum ditolak", () => {
  const invalid = [{ sku: "bad sku" }, { name: " " }, { category: " " }, { unit: " " }, { stock: -1 }, { stock: NaN }, { minimum: Infinity }, { lastPrice: -1 }, { stock: 1000001 }, { lastPrice: 1000000001 }];
  for (const change of invalid) assert.throws(() => saveItem([], { ...item, ...change }, false), /Lengkapi data/);
  assert.throws(() => saveItem([], item, true), /tidak ditemukan/);
  assert.throws(() => saveItem([item], item, false), /ID barang/);
  assert.throws(() => deleteItem([], item.id), /tidak ditemukan/);
  for (const raw of ["null", "{}", "invalid", JSON.stringify([item, item]), JSON.stringify([{ ...item, stock: -1 }])]) assert.throws(() => readItems(raw));
});

test("status dan ringkasan stok menipis berubah mengikuti stok serta minimum", () => {
  assert.equal(stockStatus({ stock: 0, minimum: 0 }), "Habis");
  assert.equal(stockStatus({ stock: 20, minimum: 20 }), "Stok rendah");
  assert.equal(stockStatus({ stock: 20.01, minimum: 20 }), "Tersedia");
  const edited = saveItem([item], { ...item, stock: 21 }, true);
  assert.equal(edited.filter(current => current.stock <= current.minimum).length, 0);
  assert.equal(stockStatus(edited[0]), "Tersedia");
});

test("filter ekspor menggunakan tanggal tambah yang tetap terjaga setelah edit", () => {
  const edited = saveItem([item], { ...item, name: "Kertas Baru" }, true)[0];
  const record = { date: edited.createdAt, cells: [edited.sku, edited.name] };
  assert.deepEqual(filterExportRecords([record], { start: "2026-11-03", end: "2026-11-03" }), [record]);
  assert.deepEqual(filterExportRecords([record], { start: "2026-11-04", end: "2026-11-04" }), []);
});
