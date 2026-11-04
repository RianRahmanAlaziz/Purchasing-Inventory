"use client";

import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { useCompanies } from "@/features/companies/hooks/useCompanies";
import { Button } from "@/components/ui/Button";
import { ExportButton } from "@/components/ui/ExportButton";
import { Icon } from "@/components/ui/Icon";
import { useOrders } from "../hooks/useOrders";
import { OrderTable } from "../components/OrderTable";
import { CreateOrderModal } from "../components/CreateOrderModal";

export function PurchaseOrdersModuleView() {
  const params = useSearchParams();
  const [query, setQuery] = useState(params.get("q") || "");
  const [status, setStatus] = useState(params.get("status") || "Semua status");
  const [companyFilter, setCompanyFilter] = useState("");
  const [create, setCreate] = useState(false);
  const { orders, ready } = useOrders();
  const { companies } = useCompanies();
  const filtered = orders.filter(order => `${order.id} ${order.supplier} ${order.company?.name || ""}`.toLowerCase().includes(query.toLowerCase()) && (status === "Semua status" || order.status === status) && (!companyFilter || (companyFilter === "legacy" ? !order.companyId : order.companyId === companyFilter)));
  return <>
    <div className="page-heading"><div><div className="eyebrow"><span className="status-dot"/> WORKSPACE / MANAGEMENT</div><h1>Purchase Order</h1><p>Buat dan kelola purchase order perusahaan Anda.</p></div><div className="heading-actions"><ExportButton disabled={!ready} filename="purchase-orders.csv" dateLabel="Tanggal PO" columns={["No. PO", "PT Penerbit", "Supplier", "Tanggal", "Total", "Status"]} records={filtered.map(order => ({ date: order.date, cells: [order.id, order.company?.name || "Belum ditentukan", order.supplier, order.date, order.amount, order.status] }))}/><Button disabled={!ready} onClick={() => setCreate(true)}><Icon name="plus" size={17}/> Buat Purchase Order</Button></div></div>
    <section className="panel module-panel"><div className="panel-header"><div><h2>Daftar Purchase Order <span className="count-pill">{filtered.length}</span></h2><p>Kelola pengadaan lintas PT penerbit</p></div></div><div className="table-toolbar"><label className="table-search"><Icon name="search" size={18}/><input aria-label="Cari Purchase Order" placeholder="Cari purchase order..." value={query} onChange={event => setQuery(event.target.value)}/></label><select aria-label="Filter PT penerbit" value={companyFilter} onChange={event => setCompanyFilter(event.target.value)}><option value="">Semua PT</option>{companies.map(company => <option key={company.id} value={company.id}>{company.name}</option>)}<option value="legacy">PT belum ditentukan</option></select><select aria-label="Filter status" value={status} onChange={event => setStatus(event.target.value)}><option>Semua status</option>{[...new Set(orders.map(order => order.status))].map(value => <option key={value}>{value}</option>)}</select></div><OrderTable orders={filtered} paginationKey={JSON.stringify([query, status, companyFilter])}/></section>
    {create && <CreateOrderModal onClose={() => setCreate(false)}/>}
  </>;
}
