"use client";

import { createContext, useContext, useEffect, useState } from "react";
import { stockItems } from "@/features/purchase-orders/mocks";
import { demoRecordDates } from "@/features/modules/constants/recordDates";
import { deleteItem, readItems, saveItem, type StockItem } from "../utils/items";

const storageKey = "stockly-items-v1";
const initialItems: StockItem[] = stockItems.map(item => ({ ...item, id: item.sku, lastPrice: 0, createdAt: demoRecordDates[item.sku] }));
type ItemsContextValue = {
  items: StockItem[]; ready: boolean; error: string; clearError: () => void;
  save: (item: StockItem, editing: boolean) => boolean; remove: (id: string) => boolean;
};
const ItemsContext = createContext<ItemsContextValue | null>(null);

export function ItemsProvider({ children }: { children: React.ReactNode }) {
  const [items, setItems] = useState(initialItems);
  const [ready, setReady] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    function load() {
      try {
        const raw = localStorage.getItem(storageKey);
        setItems(raw === null ? initialItems : readItems(raw));
        setReady(true); setError("");
      } catch {
        setReady(false); setError("Data barang tidak dapat dibaca. Periksa penyimpanan browser lalu muat ulang halaman.");
      }
    }
    function onStorage(event: StorageEvent) { if (event.key === storageKey || event.key === null) load(); }
    const timer = window.setTimeout(load, 0);
    window.addEventListener("storage", onStorage);
    return () => { window.clearTimeout(timer); window.removeEventListener("storage", onStorage); };
  }, []);

  function mutate(change: (current: StockItem[]) => StockItem[]) {
    if (!ready) return false;
    try {
      const raw = localStorage.getItem(storageKey);
      const next = change(raw === null ? items : readItems(raw));
      localStorage.setItem(storageKey, JSON.stringify(next));
      setItems(next); setError(""); return true;
    } catch (cause) {
      setError(cause instanceof Error && !(cause instanceof DOMException) ? cause.message : "Perubahan belum tersimpan. Penyimpanan browser penuh atau tidak tersedia.");
      return false;
    }
  }
  return <ItemsContext value={{ items, ready, error, clearError: () => setError(""), save: (item, editing) => mutate(current => saveItem(current, item, editing)), remove: id => mutate(current => deleteItem(current, id)) }}>{children}</ItemsContext>;
}

export function useItems() {
  const context = useContext(ItemsContext);
  if (!context) throw new Error("useItems requires ItemsProvider");
  return context;
}
