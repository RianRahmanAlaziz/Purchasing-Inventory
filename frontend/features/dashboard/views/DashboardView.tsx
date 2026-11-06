"use client";

import { useState } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { useSession } from "@/features/auth/Session";
import { useApiData } from "@/features/modules/hooks/useApiData";
import { documentMoney } from "@/features/purchase-orders/utils/totals";
import { formatQuantity } from "@/lib/quantity";

type Overview = { sections: {
  purchase_orders?: { total: number; by_status: Record<string, number>; approved_or_later_amount: string };
  orders?: { total: number };
  goods_receipts?: { total: number; confirmed_count: number };
  inventory?: { low_stock_items: number; movement_count: number };
  distributions?: { total: number };
} };
type Activity = { event_id: number; source: string; action: string; reference_id: number; occurred_at: string };
type LowStock = { id: number; sku: string; name: string; unit: string; stock_quantity: string; minimum_stock: string };

const sourceLabels: Record<string, string> = {
  purchase_order: "Purchase Order", goods_receipt: "Penerimaan", distribution: "Distribusi", stock_movement: "Mutasi stok",
};

export function DashboardView() {
  const user = useSession();
  const [revision, setRevision] = useState(0);
  const overview = useApiData<Overview>("dashboard/overview", revision);
  const activity = useApiData<Activity[]>(overview.data ? "dashboard/activity?per_page=5" : null, revision);
  const lowStock = useApiData<LowStock[]>(overview.data?.sections.inventory ? "dashboard/low-stock?per_page=5" : null, revision);
  const section = overview.data?.sections ?? {};
  const stats = [
    section.purchase_orders && { title: "Total Purchase Order", value: section.purchase_orders.total, icon: "cart", tone: "mint", href: "/purchase-orders" },
    section.purchase_orders && { title: "Menunggu Persetujuan", value: section.purchase_orders.by_status.pending_approval ?? 0, icon: "clock", tone: "peach", href: "/approvals?status=pending_approval" },
    section.purchase_orders && { title: "Nilai PO Disetujui atau Lanjut", value: documentMoney(section.purchase_orders.approved_or_later_amount), icon: "wallet", tone: "blue", href: "/purchase-orders" },
    section.orders && { title: "Order", value: section.orders.total, icon: "file", tone: "mint", href: "/orders" },
    section.goods_receipts && { title: "Penerimaan Dikonfirmasi", value: section.goods_receipts.confirmed_count, icon: "box", tone: "blue", href: "/goods-receipts" },
    section.inventory && { title: "Barang Stok Menipis", value: section.inventory.low_stock_items, icon: "alert", tone: "lavender", href: "/items" },
    section.inventory && { title: "Mutasi Stok", value: section.inventory.movement_count, icon: "arrows", tone: "mint", href: "/stock-movements" },
    section.distributions && { title: "Distribusi", value: section.distributions.total, icon: "truck", tone: "peach", href: "/distributions" },
  ];
  return <>
    <div className="page-heading"><div><div className="eyebrow"><span className="status-dot"/> WORKSPACE OVERVIEW</div><h1>Dashboard</h1><p>Selamat datang, {user.name}.</p></div>
      <Button variant="secondary" disabled={overview.loading} onClick={() => setRevision(current => current + 1)}>Muat ulang</Button>
    </div>
    {overview.loading && <p className="loading-state" role="status">Memuat ringkasan...</p>}
    {overview.error && <p className="form-error" role="alert">{overview.error}</p>}
    {overview.status === 403 && user.employee_id && <Link className="text-link" href="/distributions">Buka distribusi barang</Link>}
    {overview.data && <>
      <div className="stats-grid">{stats.map(stat => stat && <Link className="stat-card" href={stat.href} key={stat.title}>
        <div className="stat-top"><span>{stat.title}</span><span className={"stat-icon " + stat.tone}><Icon name={stat.icon} size={19}/></span></div>
        <strong className="stat-value">{stat.value}</strong>
      </Link>)}</div>
      {!Object.keys(section).length && <p className="form-hint">Belum ada ringkasan yang tersedia untuk akun ini.</p>}
      <div className="bottom-grid">
        {section.inventory && <section className="panel low-stock-panel">
          <div className="panel-header"><div><h2>Stok Menipis</h2><p>Barang yang mencapai stok minimum.</p></div><Link className="text-link" href="/items">Lihat stok</Link></div>
          <div className="stock-list">
            {lowStock.loading && <p className="form-hint">Memuat stok...</p>}
            {lowStock.error && <p className="form-error" role="alert">{lowStock.error}</p>}
            {lowStock.data?.map(item => <div className="stock-row" key={item.id}><div><strong>{item.name}</strong><small>{item.sku} · Min. {formatQuantity(item.minimum_stock)} {item.unit}</small></div><div className="stock-quantity">{formatQuantity(item.stock_quantity)} {item.unit}</div></div>)}
            {lowStock.data?.length === 0 && <p className="form-hint">Tidak ada stok menipis.</p>}
          </div>
        </section>}
        <section className="panel activity-panel">
          <div className="panel-header"><div><h2>Aktivitas Terbaru</h2></div></div>
          <div className="activity-list">
            {activity.loading && <p className="form-hint">Memuat aktivitas...</p>}
            {activity.error && <p className="form-error" role="alert">{activity.error}</p>}
            {activity.data?.map(event => <div className="activity" key={event.source + "-" + event.event_id}>
              <span className="activity-icon mint"><Icon name="file" size={16}/></span>
              <div><strong>{sourceLabels[event.source] ?? event.source} #{event.reference_id}</strong><p>{event.action.replaceAll("_", " ")}</p><small>{event.occurred_at}</small></div>
            </div>)}
            {activity.data?.length === 0 && <p className="form-hint">Belum ada aktivitas.</p>}
          </div>
        </section>
      </div>
    </>}
  </>;
}
