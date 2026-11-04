import type { PurchaseOrder } from "../types";
export const initialOrders: PurchaseOrder[] = [
  { id: "PO-2026-0048", supplier: "PT Sumber Makmur", initials: "SM", color: "lavender", date: "2026-11-03", amount: 12500000, items: 8, status: "Pending Approval" },
  { id: "PO-2026-0047", supplier: "CV Prima Sejahtera", initials: "PS", color: "peach", date: "2026-11-03", amount: 8750000, items: 5, status: "Approved" },
  { id: "PO-2026-0046", supplier: "PT Mitra Teknologi", initials: "MT", color: "blue", date: "2026-11-02", amount: 24300000, items: 12, status: "Sent" },
  { id: "PO-2026-0045", supplier: "CV Berkah Abadi", initials: "BA", color: "mint", date: "2026-11-02", amount: 4200000, items: 3, status: "Draft" },
  { id: "PO-2026-0044", supplier: "PT Sumber Makmur", initials: "SM", color: "lavender", date: "2026-11-01", amount: 6800000, items: 6, status: "Received by Supplier" },
];
export const stockItems = [
  { name: "Kertas A4 80 gsm", sku: "ATK-001", category: "Alat Tulis Kantor", stock: 8, minimum: 20, unit: "rim", icon: "file", color: "lavender" },
  { name: "Tinta Printer Epson 003", sku: "ATK-008", category: "Alat Tulis Kantor", stock: 3, minimum: 10, unit: "botol", icon: "box", color: "peach" },
  { name: "Hand Sanitizer 500 ml", sku: "KBR-012", category: "Kebersihan", stock: 5, minimum: 15, unit: "botol", icon: "box", color: "mint" },
  { name: "Map Ordner A4", sku: "ATK-015", category: "Alat Tulis Kantor", stock: 6, minimum: 12, unit: "pcs", icon: "file", color: "blue" },
];
