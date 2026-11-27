"use client";

import { useState } from "react";
import Link from "next/link";
import { useApiData } from "@/features/modules/hooks/useApiData";
import { Icon } from "@/components/ui/Icon";
import { formatQuantity } from "@/lib/quantity";
import { CompanySelect } from "./CompanySelect";

type Balance = { item_id: number; item_name: string; company_id: number | null; company_name: string | null; quantity: string };
type Overview = {
  balances: Balance[];
  totals: { available: string; in_transit: string; assets_available: number; assets_in_use: number; unallocated: string };
  low_stock: { item_id: number; item_name: string; company_id: number | null; company_name: string | null; quantity: string }[];
};

export function InventoryOverview({ companyId = "", revision = 0, showCompanyFilter = false }: {
  companyId?: string; revision?: number; showCompanyFilter?: boolean;
}) {
  const [selection, setSelection] = useState("");
  const selected = showCompanyFilter ? selection : companyId;
  const overview = useApiData<Overview>("inventory/overview" + (selected ? "?company_id=" + selected : ""), revision);
  const totals = overview.data?.totals;
  const metrics = totals ? [
    { label: "Stok tersedia", value: totals.available, icon: "stock", tone: "mint" },
    { label: "Dalam perjalanan", value: totals.in_transit, icon: "truck", tone: "blue" },
    { label: "Aset tersedia", value: totals.assets_available, icon: "box", tone: "lavender" },
    { label: "Aset digunakan", value: totals.assets_in_use, icon: "employee", tone: "peach" },
    { label: "Belum dipetakan", value: totals.unallocated, icon: "alert", tone: Number(totals.unallocated) > 0 ? "peach" : "blue" },
  ] : [];

  return <section className="panel module-panel inventory-overview">
    <div className="panel-header"><div><h2>Persediaan per PT</h2><p>{selected ? "Saldo PT yang dipilih." : "Ringkasan seluruh PT. Jumlah merupakan gabungan satuan barang."}</p></div>
      {showCompanyFilter && <CompanySelect compact value={selection} onChange={setSelection} label="Filter PT persediaan"/>}
    </div>
    {overview.loading && <p className="loading-state" role="status">Memuat persediaan...</p>}
    {overview.error && <p className="form-error" role="alert">{overview.error}</p>}
    {totals && <>
      <div className="inventory-metrics">{metrics.map(metric => <div className="inventory-metric" key={metric.label}>
        <span className={"stat-icon " + metric.tone}><Icon name={metric.icon} size={17}/></span>
        <div><small>{metric.label}</small><strong>{formatQuantity(metric.value)}</strong></div>
      </div>)}</div>
      {Number(totals.unallocated) > 0 && <p className="form-hint">Stok lama belum ditetapkan ke PT. Petakan melalui detail Stok Barang setelah lokasi fisik diperiksa.</p>}
      {showCompanyFilter && <>
        <div className="table-scroll"><table>
          <thead><tr><th>Barang</th><th>PT</th><th>Stok tersedia</th></tr></thead>
          <tbody>{overview.data?.balances.slice(0, 20).map(balance => <tr key={`${balance.item_id}-${balance.company_id ?? "unallocated"}`}>
            <td>{balance.item_name}</td><td>{balance.company_name ?? "Belum dipetakan"}</td><td>{formatQuantity(balance.quantity)}</td>
          </tr>)}</tbody>
        </table></div>
        {overview.data?.balances.length === 0 && <p className="form-hint">Belum ada saldo persediaan.</p>}
        <Link className="text-link" href={"/items" + (selected ? "?company_id=" + selected : "")}>Lihat seluruh stok</Link>
        <h3>Stok menipis per PT</h3>
        {overview.data?.low_stock.map(balance => <p key={`${balance.item_id}-${balance.company_id}`}>
          {balance.item_name} · {balance.company_name ?? "Belum dipetakan"} · {formatQuantity(balance.quantity)}
        </p>)}
        {overview.data?.low_stock.length === 0 && <p className="form-hint">Tidak ada stok menipis.</p>}
      </>}
    </>}
  </section>;
}
