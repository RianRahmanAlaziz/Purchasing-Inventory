import test from "node:test";
import assert from "node:assert/strict";
import { exportRange, filterExportRecords, validDate, workspaceToday } from "../export-period.ts";

test("7 hari mencakup hari ini dan enam hari sebelumnya, tanpa data masa depan", () => {
  const range = exportRange("week", { start: "", end: "" }, "2026-11-04");
  assert.deepEqual(range, { start: "2026-10-29", end: "2026-11-04" });
  const rows = ["2026-10-28", "2026-10-29", "2026-11-04", "2026-11-05"].map(date => ({ date, cells: [date] }));
  assert.deepEqual(filterExportRecords(rows, range).map(row => row.date), ["2026-10-29", "2026-11-04"]);
});

test("1 bulan memakai bulan kalender dan menangani akhir bulan, tahun baru, serta tahun kabisat", () => {
  for (const [today, expected] of [["2026-11-04", "2026-10-04"], ["2026-03-31", "2026-02-28"], ["2024-03-31", "2024-02-29"], ["2026-01-05", "2025-12-05"]]) {
    assert.deepEqual(exportRange("month", { start: "", end: "" }, today), { start: expected, end: today });
  }
});

test("tanggal kustom inklusif, dapat satu hari, serta menolak tanggal kosong, terbalik, dan tidak valid", () => {
  const range = exportRange("custom", { start: "2026-11-03", end: "2026-11-03" }, "2026-11-04");
  const records = [{ date: "2026-11-03", cells: [1] }, { date: "2026-11-04", cells: [2] }];
  assert.deepEqual(filterExportRecords(records, range), [records[0]]);
  for (const custom of [{ start: "", end: "" }, { start: "2026-11-04", end: "2026-11-03" }, { start: "2026-02-30", end: "2026-03-01" }]) {
    assert.throws(() => exportRange("custom", custom, "2026-11-04"));
  }
  assert.equal(validDate("2024-02-29"), true);
  assert.equal(validDate("2026-02-29"), false);
});

test("data tanpa tanggal dikecualikan hanya saat periode aktif; hasil kosong dan Semua tanggal tetap benar", () => {
  const records = [{ cells: ["legacy"] }, { date: "invalid", cells: ["invalid"] }, { date: "2026-11-03", cells: ["dated"] }];
  assert.deepEqual(filterExportRecords(records, { start: "2026-11-01", end: "2026-11-04" }), [records[2]]);
  assert.deepEqual(filterExportRecords(records, { start: "2026-01-01", end: "2026-01-07" }), []);
  assert.equal(filterExportRecords(records, exportRange("all", { start: "", end: "" }, "2026-11-04")), records);
  assert.equal(records.length, 3);
});

test("hari ini mengikuti zona workspace Asia/Bangkok, termasuk pergantian hari UTC", () => {
  assert.equal(workspaceToday(new Date("2026-11-03T16:59:59Z")), "2026-11-03");
  assert.equal(workspaceToday(new Date("2026-11-03T17:00:00Z")), "2026-11-04");
});
