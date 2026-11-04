import { validDate, workspaceToday } from "../../../lib/export-period.ts";

export type StockItem = {
  id: string; sku: string; name: string; category: string; unit: string;
  stock: number; minimum: number; lastPrice: number; createdAt?: string;
  icon: string; color: string;
};

export function stockStatus(item: Pick<StockItem, "stock" | "minimum">) {
  return item.stock === 0 ? "Habis" : item.stock <= item.minimum ? "Stok rendah" : "Tersedia";
}

export function validItem(value: unknown): value is StockItem {
  if (!value || typeof value !== "object") return false;
  const item = value as StockItem;
  return [item.id, item.sku, item.name, item.category, item.unit, item.icon, item.color].every(field => typeof field === "string" && !!field.trim())
    && /^[A-Za-z0-9_-]{1,40}$/.test(item.sku)
    && item.name.length <= 120 && item.category.length <= 80 && item.unit.length <= 20
    && [item.stock, item.minimum, item.lastPrice].every(number => typeof number === "number" && Number.isFinite(number) && number >= 0)
    && item.stock <= 1000000 && item.minimum <= 1000000 && item.lastPrice <= 1000000000
    && (item.createdAt === undefined || (typeof item.createdAt === "string" && validDate(item.createdAt)));
}

export function readItems(raw: string): StockItem[] {
  const items: unknown = JSON.parse(raw);
  if (!Array.isArray(items) || !items.every(validItem)
    || new Set(items.map(item => item.id)).size !== items.length
    || new Set(items.map(item => item.sku.toUpperCase())).size !== items.length) throw new Error("Data barang tersimpan tidak valid.");
  return items;
}

export function saveItem(items: StockItem[], input: StockItem, editing: boolean): StockItem[] {
  const item = { ...input, sku: input.sku.trim().toUpperCase(), name: input.name.trim(), category: input.category.trim(), unit: input.unit.trim() };
  if (!validItem(item)) throw new Error("Lengkapi data barang. SKU hanya boleh berisi huruf, angka, tanda hubung, atau garis bawah. Stok dan harga harus angka nonnegatif dalam batas yang tersedia.");
  const existing = items.find(current => current.id === item.id);
  if (editing && !existing) throw new Error("Barang tidak ditemukan.");
  if (!editing && existing) throw new Error("ID barang sudah digunakan.");
  if (items.some(current => current.id !== item.id && current.sku.toUpperCase() === item.sku)) throw new Error("SKU sudah digunakan oleh barang lain.");
  const saved = { ...item, createdAt: editing ? existing?.createdAt : workspaceToday() };
  return editing ? items.map(current => current.id === item.id ? saved : current) : [...items, saved];
}

export function deleteItem(items: StockItem[], id: string): StockItem[] {
  if (!items.some(item => item.id === id)) throw new Error("Barang tidak ditemukan.");
  return items.filter(item => item.id !== id);
}
