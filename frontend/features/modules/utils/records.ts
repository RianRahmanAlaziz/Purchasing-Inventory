import { modules } from "../constants/index.ts";
import { demoRecordDates } from "../constants/recordDates.ts";
import { validDate } from "../../../lib/export-period.ts";

type Field = { type?: "email" | "date" | "amount" | "quantity" | "signedQuantity" | "count"; placeholder?: string };
export const moduleSchemas: Record<string, { fields: Field[]; statuses: string[]; code: string }> = {
  "goods-receipts": { code: "GR", fields: [{}, {}, {}, { type: "quantity", placeholder: "24 item" }], statuses: ["Pending", "Partially Received", "Fully Received"] },
  payments: { code: "PAY", fields: [{}, {}, {}, { type: "amount" }], statuses: ["Unpaid", "Pending Verification", "Paid"] },
  receipts: { code: "DOC", fields: [{}, {}, {}, { type: "date" }], statuses: ["Uploaded", "Verified", "Rejected"] },
  "stock-movements": { code: "MOV", fields: [{}, {}, {}, { type: "signedQuantity", placeholder: "-12 rim" }], statuses: ["Masuk", "Keluar", "Penyesuaian"] },
  distributions: { code: "DIST", fields: [{}, {}, {}, { type: "quantity", placeholder: "5 item" }], statuses: ["Requested", "Approved", "Preparing", "Sent", "Received", "Rejected"] },
  suppliers: { code: "SUP", fields: [{}, {}, {}, { type: "email" }], statuses: ["Aktif", "Nonaktif"] },
  categories: { code: "CAT", fields: [{}, {}, {}, { type: "count", placeholder: "45 barang" }], statuses: ["Aktif", "Nonaktif"] },
};
export type ModuleRecord = { id: string; cells: string[]; date: string };

export function validateRecord(slug: string, value: unknown): value is ModuleRecord {
  const schema = moduleSchemas[slug];
  if (!schema || !value || typeof value !== "object") return false;
  const record = value as ModuleRecord;
  if (typeof record.id !== "string" || !record.id.trim() || typeof record.date !== "string" || !validDate(record.date)
    || !Array.isArray(record.cells) || record.cells.length !== schema.fields.length + 1
    || record.cells.some(cell => typeof cell !== "string" || !cell.trim() || cell.length > 300)
    || !/^[A-Za-z0-9_-]{1,60}$/.test(record.cells[0]) || !schema.statuses.includes(record.cells.at(-1)!)) return false;
  return schema.fields.every((field, index) => {
    const cell = record.cells[index];
    if (field.type === "email") return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cell);
    if (field.type === "date") return validDate(cell) && cell === record.date;
    if (field.type === "amount") return /^\d+(\.\d{1,2})?$/.test(cell) && Number(cell) > 0 && Number(cell) <= 1000000000000;
    if (["quantity", "signedQuantity", "count"].includes(field.type || "")) {
      const match = cell.match(/^([+-]?\d+(?:\.\d{1,2})?)\s+([^\d\s].*)$/);
      if (!match) return false;
      const quantity = Number(match[1]);
      if (Math.abs(quantity) > 1000000) return false;
      if (field.type === "count") return Number.isInteger(quantity) && quantity >= 0;
      if (field.type === "signedQuantity") {
        const status = record.cells.at(-1);
        return quantity !== 0 && (status === "Masuk" ? quantity > 0 : status === "Keluar" ? quantity < 0 : true);
      }
      return quantity > 0;
    }
    return true;
  });
}

export function initialModuleRecords(slug: string): ModuleRecord[] {
  if (!moduleSchemas[slug]) throw new Error("Modul tidak mendukung CRUD ini.");
  return modules[slug].rows.map(row => {
    const cells = [...row];
    const date = demoRecordDates[row[0]];
    if (slug === "receipts") cells[3] = date;
    if (slug === "payments") cells[3] = cells[3].replace(/[^\d]/g, "");
    return { id: row[0], cells, date };
  });
}

export function readModuleRecords(slug: string, raw: string): ModuleRecord[] {
  const records: unknown = JSON.parse(raw);
  if (!Array.isArray(records) || !records.every(record => validateRecord(slug, record))
    || new Set(records.map(record => record.id)).size !== records.length
    || new Set(records.map(record => record.cells[0].toUpperCase())).size !== records.length) throw new Error("Data tersimpan tidak valid. Muat ulang setelah memeriksa penyimpanan browser.");
  return records;
}

export function saveModuleRecord(slug: string, records: ModuleRecord[], input: ModuleRecord, editing: boolean): ModuleRecord[] {
  const record = { ...input, cells: input.cells.map(cell => cell.trim()) };
  record.cells[0] = record.cells[0]?.toUpperCase();
  if (slug === "receipts") record.date = record.cells[3];
  if (!validateRecord(slug, record)) throw new Error("Lengkapi semua kolom dengan benar. Gunakan email dan tanggal yang valid; jumlah harus disertai satuan. Mutasi masuk harus positif dan keluar negatif.");
  const exists = records.some(current => current.id === record.id);
  if (editing && !exists) throw new Error("Data tidak ditemukan.");
  if (!editing && exists) throw new Error("ID data sudah digunakan.");
  if (records.some(current => current.id !== record.id && current.cells[0].toUpperCase() === record.cells[0])) throw new Error("Nomor atau kode sudah digunakan.");
  return editing ? records.map(current => current.id === record.id ? record : current) : [...records, record];
}

export function deleteModuleRecord(records: ModuleRecord[], id: string): ModuleRecord[] {
  if (!records.some(record => record.id === id)) throw new Error("Data tidak ditemukan.");
  return records.filter(record => record.id !== id);
}
