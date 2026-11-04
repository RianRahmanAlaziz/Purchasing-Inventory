"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { useItems } from "../hooks/useItems";
import type { StockItem } from "../utils/items";

export function ItemForm({ initial, editing, close, onSaved }: { initial: StockItem; editing: boolean; close: () => void; onSaved: () => void }) {
  const { items, ready, save, error } = useItems();
  const [item, setItem] = useState(initial);
  const categories = [...new Set(["Alat Tulis Kantor", "Kebersihan", "Elektronik", ...items.map(current => current.category)])];
  return <Modal title={editing ? "Edit Barang" : "Tambah Barang"} onClose={close}>
    <form className="order-form company-form" onSubmit={event => { event.preventDefault(); if (save(item, editing)) { onSaved(); close(); } }}>
      <div className="form-row"><label>SKU<input autoFocus required maxLength={40} pattern="[A-Za-z0-9_\-]+" value={item.sku} onChange={event => setItem({ ...item, sku: event.target.value })} placeholder="ATK-001"/></label><label>Nama barang<input required maxLength={120} value={item.name} onChange={event => setItem({ ...item, name: event.target.value })} placeholder="Nama barang"/></label></div>
      <div className="form-row"><label>Kategori<input required list="item-categories" maxLength={80} value={item.category} onChange={event => setItem({ ...item, category: event.target.value })}/><datalist id="item-categories">{categories.map(category => <option key={category} value={category}/>)}</datalist></label><label>Satuan<input required maxLength={20} value={item.unit} onChange={event => setItem({ ...item, unit: event.target.value })} placeholder="pcs / rim / botol"/></label></div>
      <div className="form-row"><label>Stok<input required type="number" min="0" max="1000000" step="0.01" value={Number.isFinite(item.stock) ? item.stock : ""} onChange={event => setItem({ ...item, stock: event.target.value === "" ? NaN : Number(event.target.value) })}/></label><label>Stok minimum<input required type="number" min="0" max="1000000" step="0.01" value={Number.isFinite(item.minimum) ? item.minimum : ""} onChange={event => setItem({ ...item, minimum: event.target.value === "" ? NaN : Number(event.target.value) })}/></label></div>
      <label>Harga terakhir (Rp)<input required type="number" min="0" max="1000000000" step="0.01" value={Number.isFinite(item.lastPrice) ? item.lastPrice : ""} onChange={event => setItem({ ...item, lastPrice: event.target.value === "" ? NaN : Number(event.target.value) })}/></label>
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="modal-actions"><Button type="button" variant="secondary" onClick={close}>Batal</Button><Button type="submit" disabled={!ready}>Simpan Barang</Button></div>
    </form>
  </Modal>;
}
