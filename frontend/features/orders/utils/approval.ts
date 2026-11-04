import type { PurchaseOrder } from "../../purchase-orders/types/index.ts";

export function decidePurchaseOrder(orders: PurchaseOrder[], id: string, decision: "Approved" | "Rejected", reviewer = "", reason = ""): PurchaseOrder[] {
  const current = orders.find(order => order.id === id);
  if (!current) throw new Error("PO tidak ditemukan.");
  if (current.status !== "Pending Approval") throw new Error("Hanya PO Pending Approval yang dapat diputuskan.");
  if (!["Approved", "Rejected"].includes(decision)) throw new Error("Keputusan tidak valid.");
  return orders.map(order => order.id === id ? {
    ...order, status: decision,
    approval: { decision, reviewer: reviewer.trim(), reason: reason.trim(), decidedAt: new Date().toISOString() },
  } : order);
}
