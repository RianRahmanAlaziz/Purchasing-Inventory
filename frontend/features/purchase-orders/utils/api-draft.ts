import type { Row } from "../../modules/api-types.ts";
import type { Company } from "../../companies/types/index.ts";
import type { OrderLine, PurchaseOrder } from "../types/index.ts";
import { quantityInput } from "../../../lib/quantity.ts";

export type TaxOption = {
  id: number; label: string; rate_percent: string; taxable_base_factor: string;
  tax_type: { direction: "addition" | "withholding" };
};
export type DraftLine = {
  id: string; itemId: string; description: string; unit: string; quantity: string; unitPrice: string;
  itemName: string; categoryId: string; stockUnit?: string;
  discountPercent: string; taxRateId: string; taxOption?: TaxOption; notes: string;
};
export function emptyLine(): DraftLine {
  return { id: crypto.randomUUID(), itemId: "", itemName: "", categoryId: "", description: "", unit: "pcs", quantity: "1",
    unitPrice: "", discountPercent: "0", taxRateId: "", notes: "" };
}
export function apiLine(row: Row): DraftLine {
  const rate = row.tax_rate_id ? {
    id: Number(row.tax_rate_id), label: String(row.tax_label ?? ""),
    rate_percent: String(row.tax_rate_percent ?? "0"), taxable_base_factor: String(row.tax_base_factor ?? "1"),
    tax_type: { direction: row.tax_direction === "withholding" ? "withholding" as const : "addition" as const },
  } : undefined;
  return { id: String(row.id), itemId: String(row.item_id), description: String(row.name ?? ""), unit: String(row.unit ?? "pcs"),
    itemName: String(row.name ?? ""), categoryId: "", stockUnit: String(row.unit ?? "pcs"),
    quantity: quantityInput(row.quantity), unitPrice: String(row.unit_price), discountPercent: String(row.discount_percent ?? "0"),
    taxRateId: rate ? String(rate.id) : "", taxOption: rate, notes: String(row.notes ?? "") };
}
export function legacyLine(line: OrderLine): DraftLine {
  return { id: line.id, itemId: "", description: line.description, unit: line.unit, quantity: quantityInput(line.quantity),
    itemName: line.description, categoryId: "",
    unitPrice: String(line.unitPrice), discountPercent: String(line.discountPercent),
    taxRateId: line.taxRate ? String(line.taxRate.id) : line.taxPercent ? "legacy" : "",
    taxOption: line.taxRate ? { id: line.taxRate.id, label: line.taxRate.label, rate_percent: String(line.taxPercent),
      taxable_base_factor: String(line.taxRate.baseFactor), tax_type: { direction: line.taxRate.direction } } : undefined,
    notes: "" };
}
export function snapshotCompany(row?: Row): Company | undefined {
  const value = row?.company_snapshot;
  if (!value || typeof value !== "object") return;
  const company = value as Company;
  return { id: String(company.id), name: company.name, address: company.address ?? "", phone: company.phone ?? "",
    email: company.email ?? "", logo: company.logo ?? "" };
}
export function readBrowserDraft(key: string): { draft?: PurchaseOrder; error: string } {
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return { error: "" };
    const draft = JSON.parse(raw) as PurchaseOrder;
    if (!draft || typeof draft.supplier !== "string" || !Array.isArray(draft.lines) || !draft.lines.length
      || draft.lines.some(line => !line || typeof line.description !== "string" || typeof line.unit !== "string"
        || ![line.quantity, line.unitPrice, line.discountPercent, line.taxPercent].every(Number.isFinite))) {
      throw new Error("invalid");
    }
    return { draft, error: "" };
  } catch { return { error: "Draft lama belum dapat dibaca. Data browser tetap disimpan." }; }
}
type PayloadInput = {
  companyId: string; supplierId: string; supplierName?: string; employeeId: string; departmentId: string; supplierAddress: string;
  date: string; expectedDate: string; notes: string; lines: DraftLine[]; taxScope: "line" | "order"; orderRateIds: string[];
};
export function poPayload(input: PayloadInput) {
  const id = /^[1-9][0-9]*$/;
  if (![input.companyId, input.employeeId, input.departmentId].every(value => id.test(value))) {
    throw new Error("Pilih PT serta karyawan dengan departemen yang aktif.");
  }
  if (input.supplierId ? !id.test(input.supplierId) : !input.supplierName?.trim()) throw new Error("Isi nama supplier.");
  if (!input.lines.length || input.lines.length > 100) throw new Error("Isi 1 hingga 100 barang pada PO.");
  if (input.taxScope === "order" && input.orderRateIds.some(value => !id.test(value))) throw new Error("Pilih ulang pajak dari database.");
  const linked = input.lines.filter(line => line.itemId).map(line => line.itemId);
  if (new Set(linked).size !== linked.length) throw new Error("Barang yang sama cukup ditulis pada satu baris.");
  const items = input.lines.map((line, index) => {
    if ((line.itemId && !id.test(line.itemId)) || !line.itemName.trim() || !line.description.trim() || !line.unit.trim()) throw new Error("Isi nama, deskripsi, dan satuan barang " + (index + 1) + ".");
    if (line.categoryId && !id.test(line.categoryId)) throw new Error("Pilih kategori barang yang valid.");
    if (!/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/.test(line.quantity) || Number(line.quantity) <= 0) throw new Error("Jumlah barang " + (index + 1) + " harus positif, maksimal 3 angka desimal.");
    if (!/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/.test(line.unitPrice)) throw new Error("Harga barang " + (index + 1) + " harus valid, maksimal 2 angka desimal.");
    if (!/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/.test(line.discountPercent) || Number(line.discountPercent) > 100) throw new Error("Diskon barang " + (index + 1) + " harus 0 hingga 100%.");
    if (input.taxScope === "line" && line.taxRateId && !id.test(line.taxRateId)) throw new Error("Pilih ulang pajak barang " + (index + 1) + " dari database.");
    return { item_id: line.itemId ? Number(line.itemId) : null, name: line.itemName.trim(),
      category_id: !line.itemId && line.categoryId ? Number(line.categoryId) : null, description: line.description.trim(), unit: line.unit.trim(),
      quantity: line.quantity, unit_price: line.unitPrice, discount_percent: line.discountPercent,
      tax_rate_id: input.taxScope === "line" && line.taxRateId ? Number(line.taxRateId) : null, notes: line.notes.trim() || null };
  });
  return { company_id: Number(input.companyId), supplier_id: input.supplierId ? Number(input.supplierId) : null,
    ...(input.supplierName?.trim() ? { supplier_name: input.supplierName.trim() } : {}), supplier_address: input.supplierAddress.trim(),
    requested_for_employee_id: Number(input.employeeId), requested_for_department_id: Number(input.departmentId),
    order_date: input.date, expected_date: input.expectedDate || null, notes: input.notes.trim() || null,
    tax_scope: input.taxScope, tax_rate_ids: input.taxScope === "order" ? input.orderRateIds.map(Number) : [], items };
}

