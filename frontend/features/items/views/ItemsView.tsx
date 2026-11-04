"use client";

import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ExportButton } from "@/components/ui/ExportButton";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import { rupiah } from "@/lib/utils";
import { ItemForm } from "../components/ItemForm";
import { useItems } from "../hooks/useItems";
import { stockStatus, type StockItem } from "../utils/items";

const columns = ["SKU", "Nama Barang", "Kategori", "Stok", "Stok Minimum", "Satuan", "Harga Terakhir", "Status", "Tanggal ditambahkan"];
const values = (item: StockItem) => [item.sku, item.name, item.category, item.stock, item.minimum, item.unit, item.lastPrice, stockStatus(item), item.createdAt || ""];

export function ItemsView() {
  const params = useSearchParams();
  const { items, ready, error, clearError, remove } = useItems();
  const [query, setQuery] = useState(params.get("q") || "");
  const [status, setStatus] = useState(params.get("status") || "Semua status");
  const [selection, setSelection] = useState<{ mode: "create" | "view" | "edit" | "delete"; item: StockItem } | null>(null);
  const [notice, setNotice] = useState("");
  const filtered = items.filter(item => `${item.sku} ${item.name} ${item.category} ${item.unit}`.toLowerCase().includes(query.toLowerCase()) && (status === "Semua status" || stockStatus(item) === status));
  const pagination = usePagination(filtered, JSON.stringify([query, status]));
  function open(mode: NonNullable<typeof selection>["mode"], item: StockItem) { clearError(); setNotice(""); setSelection({ mode, item }); }
  function close() { clearError(); setSelection(null); }
  return <>
    <div className="page-heading"><div><div className="eyebrow"><span className="status-dot"/> WORKSPACE / INVENTORY</div><h1>Stok Barang</h1><p>Kelola barang dan ketersediaan persediaan.</p></div><div className="heading-actions">
      <ExportButton filename="items.csv" disabled={!ready} dateLabel="Tanggal ditambahkan" columns={columns} records={filtered.map(item => ({ date: item.createdAt, cells: values(item) }))}/>
      <Button disabled={!ready} onClick={() => open("create", { id: crypto.randomUUID(), sku: "", name: "", category: "", unit: "pcs", stock: 0, minimum: 0, lastPrice: 0, icon: "box", color: "blue" })}><Icon name="plus" size={17}/> Tambah Barang</Button>
    </div></div>
    <p className="form-hint">Data tersimpan di browser ini. Perubahan stok belum terhubung ke penerimaan, distribusi, atau mutasi stok.</p>
    {notice && <p className="form-hint" role="status">{notice}</p>}
    {error && !selection && <p className="form-error" role="alert">{error}</p>}
    <section className="panel module-panel"><div className="panel-header"><div><h2>Daftar Barang <span className="count-pill">{filtered.length}</span></h2></div></div>
      <div className="table-toolbar"><label className="table-search"><Icon name="search" size={18}/><input aria-label="Cari barang" placeholder="Cari SKU, nama, atau kategori..." value={query} onChange={event => setQuery(event.target.value)}/></label><select aria-label="Filter status stok" value={status} onChange={event => setStatus(event.target.value)}>{["Semua status", "Tersedia", "Stok rendah", "Habis"].map(value => <option key={value}>{value}</option>)}</select></div>
      {!ready && !error ? <p className="loading-state">Memuat barang...</p> : <><div className="table-scroll"><table><thead><tr>{["SKU", "Nama Barang", "Kategori", "Stok / Minimum", "Harga Terakhir", "Status", "Aksi"].map(column => <th key={column}>{column}</th>)}</tr></thead><tbody>{pagination.items.map(item => <tr key={item.id}>
        <td><button className="table-link" onClick={() => open("view", item)}>{item.sku}</button></td><td>{item.name}</td><td>{item.category}</td><td>{item.stock} / {item.minimum} {item.unit}</td><td>{rupiah(item.lastPrice)}</td><td><Badge tone={stockStatus(item) === "Habis" ? "red" : stockStatus(item) === "Stok rendah" ? "amber" : "green"}>{stockStatus(item)}</Badge></td>
        <td><div className="po-row-actions"><button className="icon-button po-action-view" title="Lihat barang" aria-label={`Lihat ${item.sku}`} onClick={() => open("view", item)}><Icon name="eye" size={17}/></button><button className="icon-button po-action-edit" title="Edit barang" aria-label={`Edit ${item.sku}`} disabled={!ready} onClick={() => open("edit", item)}><Icon name="edit" size={17}/></button><button className="icon-button po-action-delete" title="Hapus barang" aria-label={`Hapus ${item.sku}`} disabled={!ready} onClick={() => open("delete", item)}><Icon name="trash" size={17}/></button></div></td>
      </tr>)}</tbody></table>{!filtered.length && <div className="empty-state"><Icon name="box" size={32}/><h3>Barang tidak ditemukan</h3><p>Tambahkan barang atau ubah pencarian dan filter.</p></div>}</div><Pagination {...pagination} label="barang"/></>}
    </section>
    {selection && (selection.mode === "create" || selection.mode === "edit") && <ItemForm key={selection.item.id} initial={selection.item} editing={selection.mode === "edit"} close={close} onSaved={() => setNotice("Barang berhasil disimpan.")}/>}
    {selection?.mode === "view" && <Modal title="Detail Barang" onClose={close}><div className="detail-grid">{values(selection.item).map((value, index) => <p key={columns[index]}>{columns[index]}<strong>{index === 6 ? rupiah(Number(value)) : value || (value === 0 ? 0 : "Belum tercatat")}</strong></p>)}</div><div className="modal-actions"><Button variant="secondary" onClick={close}>Tutup</Button><Button disabled={!ready} onClick={() => open("edit", selection.item)}>Edit Barang</Button></div></Modal>}
    {selection?.mode === "delete" && <Modal title="Hapus Barang?" onClose={close}><div className="po-delete-notice"><span><Icon name="trash" size={25}/></span><p>Hapus <strong>{selection.item.name}</strong> ({selection.item.sku}) dari daftar? Tindakan ini tidak dapat dibatalkan.</p></div>{error && <p className="form-error" role="alert">{error}</p>}<div className="modal-actions"><Button variant="secondary" autoFocus onClick={close}>Batal</Button><Button className="danger" disabled={!ready} onClick={() => { if (remove(selection.item.id)) { setNotice("Barang berhasil dihapus."); close(); } }}>Ya, Hapus Barang</Button></div></Modal>}
  </>;
}
