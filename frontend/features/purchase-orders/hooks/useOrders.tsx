"use client";
import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { initialOrders } from "../mocks";
import { editPurchaseOrder, insertPurchaseOrder, removePurchaseOrder, submitPurchaseOrder } from "../utils/mutations";
import { decidePurchaseOrder } from "@/features/orders/utils/approval";
import type { PurchaseOrder } from "../types";

type OrdersContextValue = {
  orders: PurchaseOrder[]; ready: boolean; error: string; clearError: () => void;
  addOrder: (order: PurchaseOrder) => boolean;
  updateOrder: (order: PurchaseOrder) => boolean;
  deleteOrder: (id: string) => boolean;
  submitOrder: (id: string) => boolean;
  decideOrder: (id: string, decision: "Approved" | "Rejected") => boolean;
};
const OrdersContext = createContext<OrdersContextValue | null>(null);
export function OrdersProvider({ children }: { children: React.ReactNode }) {
  const [orders, setOrders] = useState(initialOrders);
  const currentOrders = useRef(initialOrders);
  const [ready, setReady] = useState(false);
  const [error, setError] = useState("");
  const clearError = useCallback(() => setError(""), []);
  useEffect(() => {
    try {
      const saved = localStorage.getItem("stockly-orders");
      if (saved) {
        const parsed = JSON.parse(saved);
        if (Array.isArray(parsed) && parsed.every(item => item && typeof item.id === "string" && typeof item.supplier === "string" && typeof item.amount === "number" && typeof item.date === "string")) {
          currentOrders.current = parsed;
          setOrders(parsed);
        }
      }
    } catch { setError("Data PO tidak dapat dibaca dari penyimpanan browser."); }
    setReady(true);
  }, []);

  function persist(change: (current: PurchaseOrder[]) => PurchaseOrder[]) {
    if (!ready) { setError("Tunggu hingga data PO selesai dimuat."); return false; }
    let next: PurchaseOrder[];
    try { next = change(currentOrders.current); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Perubahan PO tidak valid."); return false; }
    try {
      localStorage.setItem("stockly-orders", JSON.stringify(next));
      currentOrders.current = next;
      setOrders(next); setError(""); return true;
    } catch { setError("Perubahan PO belum tersimpan. Penyimpanan browser penuh atau tidak tersedia. Coba perkecil logo PT."); return false; }
  }
  return <OrdersContext value={{ orders, ready, error, clearError, addOrder: order => persist(current => insertPurchaseOrder(current, order)), updateOrder: order => persist(current => editPurchaseOrder(current, order)), deleteOrder: id => persist(current => removePurchaseOrder(current, id)), submitOrder: id => persist(current => submitPurchaseOrder(current, id)), decideOrder: (id, decision) => persist(current => decidePurchaseOrder(current, id, decision)) }}>{children}</OrdersContext>;
}
export function useOrders() {
  const context = useContext(OrdersContext);
  if (!context) throw new Error("useOrders requires OrdersProvider");
  return context;
}
