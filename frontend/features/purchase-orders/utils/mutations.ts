import type { PurchaseOrder } from "../types/index.ts";
import { orderTotals } from "./totals.ts";

export const canEditPurchaseOrder = (status: string) => status === "Draft";
export const canDeletePurchaseOrder = (status: string) => ["Draft", "Rejected", "Cancelled"].includes(status);

function validateDraft(order: PurchaseOrder): PurchaseOrder {
  if (!order.supplier.trim() || !order.company?.name.trim() || order.companyId !== order.company.id) {
    throw new Error("Lengkapi supplier dan PT penerbit sebelum menyimpan PO.");
  }
  if (!order.date || !order.paymentTerms?.trim()) throw new Error("Lengkapi tanggal pesanan dan termin pembayaran.");
  if (order.expectedDate && order.expectedDate < order.date) throw new Error("Tanggal kedatangan tidak boleh sebelum tanggal pemesanan.");
  if (!order.lines?.length || order.lines.some(line =>
    !line.description.trim() || !line.unit.trim() ||
    !Number.isFinite(line.quantity) || line.quantity < .01 || line.quantity > 1000000 ||
    !Number.isFinite(line.unitPrice) || line.unitPrice < .01 || line.unitPrice > 1000000000 ||
    !Number.isFinite(line.discountPercent) || line.discountPercent < 0 || line.discountPercent > 100 ||
    !Number.isFinite(line.taxPercent) || line.taxPercent < 0 || line.taxPercent > 100 ||
    (line.taxRate !== undefined && (
      !Number.isInteger(line.taxRate.id) || line.taxRate.id <= 0 ||
      typeof line.taxRate.label !== "string" || !line.taxRate.label.trim() ||
      !["addition", "withholding"].includes(line.taxRate.direction) ||
      !Number.isFinite(line.taxRate.baseFactor) || line.taxRate.baseFactor <= 0 || line.taxRate.baseFactor > 100
    ))
  )) throw new Error("Lengkapi minimal satu barang dengan jumlah, satuan, harga, diskon, dan pajak yang valid.");
  const total = orderTotals(order.lines).total;
  if (total < 0) throw new Error("Pajak pemotong tidak boleh membuat total PO negatif.");
  return { ...order, amount: total, items: order.lines.length };
}

export function insertPurchaseOrder(orders: PurchaseOrder[], order: PurchaseOrder): PurchaseOrder[] {
  if (orders.some(current => current.id === order.id)) throw new Error("Nomor PO sudah digunakan. Tutup form dan coba lagi.");
  if (order.status !== "Draft") throw new Error("PO baru harus disimpan sebagai Draft.");
  return [validateDraft(order), ...orders];
}

export function editPurchaseOrder(orders: PurchaseOrder[], order: PurchaseOrder): PurchaseOrder[] {
  const current = orders.find(item => item.id === order.id);
  if (!current) throw new Error("PO tidak ditemukan. Muat ulang daftar purchase order.");
  if (!canEditPurchaseOrder(current.status)) throw new Error("Hanya PO berstatus Draft yang dapat diedit.");
  const updated = validateDraft({ ...order, id: current.id, status: current.status });
  return orders.map(item => item.id === current.id ? updated : item);
}

export function removePurchaseOrder(orders: PurchaseOrder[], id: string): PurchaseOrder[] {
  const current = orders.find(item => item.id === id);
  if (!current) throw new Error("PO tidak ditemukan. Muat ulang daftar purchase order.");
  if (!canDeletePurchaseOrder(current.status)) throw new Error("Hanya PO berstatus Draft, Rejected, atau Cancelled yang dapat dihapus.");
  return orders.filter(item => item.id !== id);
}

export function submitPurchaseOrder(orders: PurchaseOrder[], id: string): PurchaseOrder[] {
  const current = orders.find(order => order.id === id);
  if (!current) throw new Error("PO tidak ditemukan.");
  if (current.status !== "Draft") throw new Error("Hanya PO Draft yang dapat diajukan.");
  const validated = validateDraft(current);
  return orders.map(order => order.id === id ? { ...validated, status: "Pending Approval", submittedAt: new Date().toISOString() } : order);
}
