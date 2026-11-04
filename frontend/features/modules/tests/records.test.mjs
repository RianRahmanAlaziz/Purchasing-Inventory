import test from "node:test";
import assert from "node:assert/strict";
import { moduleSchemas, initialModuleRecords, readModuleRecords, saveModuleRecord, deleteModuleRecord } from "../utils/records.ts";
import { filterExportRecords } from "../../../lib/export-period.ts";

for (const slug of Object.keys(moduleSchemas)) {
  test(`${slug}: tambah, baca ulang, edit, hapus, dan pertahankan hasil kosong`, () => {
    const initial = initialModuleRecords(slug);
    assert.deepEqual(readModuleRecords(slug, JSON.stringify(initial)), initial);
    const draft = { ...initial[0], id: "new-record", cells: [...initial[0].cells] };
    draft.cells[0] = " test-001 ";
    const created = saveModuleRecord(slug, initial, draft, false);
    assert.equal(initial.length + 1, created.length);
    assert.equal(created.at(-1).cells[0], "TEST-001");
    const restored = readModuleRecords(slug, JSON.stringify(created));
    assert.deepEqual(restored, created);
    const changed = { ...restored.at(-1), cells: [...restored.at(-1).cells] };
    changed.cells[1] = "Nama baru";
    const edited = saveModuleRecord(slug, restored, changed, true);
    assert.equal(edited.at(-1).cells[1], "Nama baru");
    assert.equal(restored.at(-1).cells[1], initial[0].cells[1]);
    assert.equal(edited[0], restored[0]);
    assert.deepEqual(deleteModuleRecord(edited, "new-record"), initial);
    assert.deepEqual(readModuleRecords(slug, JSON.stringify(deleteModuleRecord([initial[0]], initial[0].id))), []);
  });

  test(`${slug}: kode duplikat, status palsu, dan data hilang ditolak`, () => {
    const initial = initialModuleRecords(slug);
    const duplicate = { ...initial[0], id: "other", cells: [...initial[0].cells] };
    duplicate.cells[0] = duplicate.cells[0].toLowerCase();
    assert.throws(() => saveModuleRecord(slug, initial, duplicate, false), /sudah digunakan/);
    const badStatus = { ...duplicate, cells: [...duplicate.cells.slice(0, -1), "Invalid"] };
    assert.throws(() => saveModuleRecord(slug, [], badStatus, false), /Lengkapi/);
    assert.throws(() => saveModuleRecord(slug, [], initial[0], true), /tidak ditemukan/);
    assert.throws(() => deleteModuleRecord([], "missing"), /tidak ditemukan/);
    assert.throws(() => readModuleRecords(slug, JSON.stringify([initial[0], initial[0]])), /tidak valid/);
  });
}

test("validasi email, nominal, jumlah bersatuan, dan arah mutasi", () => {
  for (const [slug, value] of [["suppliers", "not-an-email"], ["payments", "-1"], ["payments", "NaN"], ["distributions", "0 item"], ["goods-receipts", "-2 item"], ["categories", "1.5 barang"], ["stock-movements", "+12 rim"]]) {
    const record = initialModuleRecords(slug)[0];
    record.cells[3] = value;
    assert.throws(() => saveModuleRecord(slug, [], record, false), /Lengkapi/);
  }
});

test("tanggal dokumen menjadi sumber filter ekspor yang sama dengan tanggal pada tabel", () => {
  const record = initialModuleRecords("receipts")[0];
  record.cells[3] = "2026-10-01";
  const saved = saveModuleRecord("receipts", [record], record, true)[0];
  assert.equal(saved.date, "2026-10-01");
  assert.deepEqual(filterExportRecords([{ date: saved.date, cells: saved.cells }], { start: "2026-11-01", end: "2026-11-04" }), []);
  assert.throws(() => readModuleRecords("receipts", JSON.stringify([{ ...saved, date: "2026-11-03" }])), /tidak valid/);
});

test("tanggal salah dan penyimpanan rusak ditolak tanpa mengganti daftar", () => {
  const initial = initialModuleRecords("suppliers");
  assert.throws(() => saveModuleRecord("suppliers", initial, { ...initial[0], date: "2026-02-30" }, true), /Lengkapi/);
  for (const raw of ["null", "{}", "invalid"]) assert.throws(() => readModuleRecords("suppliers", raw));
  assert.equal(initial[0].date, "2026-11-03");
});
