"use client";
import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { useOrders } from "@/features/purchase-orders/hooks/useOrders";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Icon } from "@/components/ui/Icon";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import { rupiah } from "@/lib/utils";
import { ExportButton } from "@/components/ui/ExportButton";
import { OrderApprovalModal } from "../components/OrderApprovalModal";

const tabs = [{ value: "Pending Approval", label: "Menunggu Persetujuan" }, { value: "Approved", label: "Disetujui" }, { value: "Rejected", label: "Ditolak" }];
export function OrdersView() {
  const params = useSearchParams();
  const { orders, ready, error, clearError } = useOrders();
  const [status, setStatus] = useState(tabs.some(tab => tab.value === params.get("status")) ? params.get("status")! : "Pending Approval");
  const [query, setQuery] = useState("");
  const [company, setCompany] = useState("");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const eligible = orders.filter(order => tabs.some(tab => tab.value === order.status));
  const companyNames = [...new Set(eligible.map(order => order.company?.name || "Belum ditentukan"))];
  const filtered = eligible.filter(order => order.status === status && `${order.id} ${order.supplier} ${order.company?.name || ""}`.toLowerCase().includes(query.toLowerCase()) && (!company || (order.company?.name || "Belum ditentukan") === company));
  const pagination = usePagination(filtered, JSON.stringify([status, query, company]));
  const selected = orders.find(order => order.id === selectedId);
  function open(id: string) { clearError(); setSelectedId(id); }
  return <>
    <div className="page-heading"><div><div className="eyebrow"><span className="status-dot"/> PURCHASING / PERSETUJUAN</div><h1>Persetujuan PO</h1><p>Periksa dan putuskan purchase order yang diajukan Purchasing.</p></div><ExportButton filename="persetujuan-po.csv" disabled={!ready} dateLabel="Tanggal PO" columns={["No. PO", "PT Penerbit", "Supplier", "Tanggal PO", "Total", "Status", "Pemeriksa", "Waktu Keputusan", "Catatan"]} records={filtered.map(order => ({ date: order.date, cells: [order.id, order.company?.name || "", order.supplier, order.date, order.amount, order.status, order.approval?.reviewer || "", order.approval?.decidedAt || "", order.approval?.reason || ""] }))}/></div>
    <section className="panel module-panel">
      <div className="panel-header"><div><h2>Daftar Persetujuan PO</h2><p>PO Draft diajukan melalui menu Purchase Order. Keputusan tersimpan di browser ini.</p></div></div>
      <div className="approval-tabs" role="group" aria-label="Status persetujuan">{tabs.map(tab => <button key={tab.value} aria-pressed={status === tab.value} className={status === tab.value ? "active" : ""} onClick={() => setStatus(tab.value)}>{tab.label} <span className="count-pill">{ready ? eligible.filter(order => order.status === tab.value).length : "…"}</span></button>)}</div>
      <div className="table-toolbar"><label className="table-search"><Icon name="search" size={18}/><input aria-label="Cari persetujuan PO" placeholder="Cari nomor PO, supplier, atau PT..." value={query} onChange={event => setQuery(event.target.value)}/></label><select aria-label="Filter PT penerbit" value={company} onChange={event => setCompany(event.target.value)}><option value="">Semua PT</option>{companyNames.map(name => <option key={name}>{name}</option>)}</select></div>
      {!ready ? <p className="loading-state">Memuat persetujuan...</p> : <><div className="table-scroll"><table><thead><tr><th>No. PO</th><th>PT Penerbit</th><th>Supplier</th><th>Tanggal PO</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>{pagination.items.map(order => <tr key={order.id}><td><button className="table-link" onClick={() => open(order.id)}>{order.id}</button></td><td>{order.company?.name || "Belum ditentukan"}</td><td>{order.supplier}</td><td>{new Date(`${order.date}T00:00:00`).toLocaleDateString("id-ID")}</td><td>{rupiah(order.amount)}</td><td><Badge tone={order.status === "Pending Approval" ? "amber" : order.status === "Rejected" ? "red" : "green"}>{order.status}</Badge></td><td><Button variant="secondary" onClick={() => open(order.id)}>{order.status === "Pending Approval" ? "Periksa PO" : "Lihat keputusan"}</Button></td></tr>)}</tbody></table>{!filtered.length && <div className="empty-state"><Icon name="file" size={32}/><h3>Tidak ada PO pada daftar ini</h3><p>Ajukan PO Draft atau ubah pencarian dan filter.</p></div>}</div><Pagination {...pagination} label="persetujuan PO"/></>}
      {error && !selected && <p className="form-error" role="alert">{error}</p>}
    </section>
    {selected && <OrderApprovalModal key={selected.id} order={selected} onClose={() => setSelectedId(null)}/>}
  </>;
}
