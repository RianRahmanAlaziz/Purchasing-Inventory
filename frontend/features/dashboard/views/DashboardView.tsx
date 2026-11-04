"use client";
import { useState } from "react";
import Link from "next/link";
import { Icon } from "@/components/ui/Icon";
import { Badge } from "@/components/ui/Badge";
import { OrderTable } from "@/features/purchase-orders/components/OrderTable";
import { CreateOrderModal } from "@/features/purchase-orders/components/CreateOrderModal";
import { useOrders } from "@/features/purchase-orders/hooks/useOrders";
import { useItems } from "@/features/items/hooks/useItems";
import { stockStatus } from "@/features/items/utils/items";
import { ExportButton } from "@/components/ui/ExportButton";
import { SpendingChart, OrderStatusChart } from "../components/Charts";
export function DashboardView() {
 const [create, setCreate] = useState(false); const { orders, ready } = useOrders(); const { items } = useItems(); const lowStockItems = items.filter(item => item.stock <= item.minimum);
 const stats = [
  { title: "Total Purchase Order", value: String(48 + orders.length - 5), icon: "cart", tone: "mint", change: "+12,8%", note: "dari bulan lalu", caption: "Purchase order bulan ini", slug: "purchase-orders" },
  { title: "Menunggu Persetujuan", value: String(orders.filter(order => order.status === "Pending Approval").length), icon: "clock", tone: "peach", change: "Perlu tindakan", note: "", caption: "PO membutuhkan persetujuan", slug: "orders" },
  { title: "Nilai Pengadaan", value: "Rp 88 jt", icon: "wallet", tone: "blue", change: "+8,2%", note: "dari bulan lalu", caption: "Total pengadaan bulan ini", slug: "payments" },
  { title: "Barang Stok Menipis", value: String(lowStockItems.length).padStart(2, "0"), icon: "box", tone: "lavender", change: "Di bawah minimum", note: "", caption: "Segera lakukan pengadaan", slug: "items" },
 ];
 return <><div className="page-heading"><div><div className="eyebrow"><span className="status-dot"/> WORKSPACE OVERVIEW</div><h1>Dashboard</h1><p>Selamat pagi, Andi <span className="wave">&#9728;</span> <span className="heading-separator">—</span> ini ringkasan bisnis Anda hari ini.</p></div><div className="heading-actions"><span className="date-label"><Icon name="calendar" size={17}/> 3 November 2026</span><ExportButton disabled={!ready} filename="ringkasan-pengadaan.csv" label="Ekspor Laporan" dateLabel="Tanggal PO" columns={["No. PO", "Supplier", "Tanggal", "Total", "Status"]} records={orders.map(order => ({ date: order.date, cells: [order.id, order.supplier, order.date, order.amount, order.status] }))}/></div></div>
 <div className="overview-note"><div className="note-icon"><Icon name="grid" size={20}/></div><div><strong>Semua kebutuhan operasional, dalam satu tempat.</strong><p>Pantau pengadaan dan persediaan Anda untuk menjaga bisnis tetap berjalan.</p></div><button onClick={() => setCreate(true)}>Buat Purchase Order <Icon name="arrow" size={18}/></button><div className="note-decoration" aria-hidden="true"><Icon name="box" size={72}/></div></div>
 <div className="stats-grid">{stats.map(s => <Link className="stat-card" href={`/${s.slug}`} key={s.title}><div className="stat-top"><span>{s.title}</span><span className={`stat-icon ${s.tone}`}><Icon name={s.icon} size={19}/></span></div><strong className="stat-value">{s.value}</strong><p>{s.caption}</p><div className="stat-bottom"><span className={s.change.startsWith("+") ? "positive" : s.tone === "peach" ? "attention" : "neutral"}>{s.change.startsWith("+") && <Icon name="trend" size={13}/>} {s.change}</span><small>{s.note}</small><Icon name="arrow" size={15}/></div></Link>)}</div>
 <div className="charts-grid"><SpendingChart/><OrderStatusChart/></div>
 <section className="panel orders-panel"><div className="panel-header"><div><h2>Purchase Order Terbaru <span className="count-pill">{orders.length}</span></h2><p>Daftar pengadaan terbaru di workspace Anda</p></div><Link href="/purchase-orders" className="text-link">Lihat semua <Icon name="arrow" size={16}/></Link></div><OrderTable orders={orders} initialPageSize={5}/></section>
 <div className="bottom-grid"><section className="panel low-stock-panel"><div className="panel-header"><div><h2><Icon name="alert" size={18}/> Stok Menipis <span className="count-pill amber">{lowStockItems.length}</span></h2><p>Barang yang perlu segera diisi kembali</p></div><Link href="/items" className="text-link">Lihat stok <Icon name="arrow" size={16}/></Link></div><div className="stock-list">{lowStockItems.slice(0,3).map(item => <div className="stock-row" key={item.sku}><span className={`item-icon ${item.color}`}><Icon name={item.icon}/></span><div><strong>{item.name}</strong><small>{item.sku} <span>·</span> Min. {item.minimum} {item.unit}</small></div><div className="stock-quantity"><strong>{item.stock} <small>{item.unit}</small></strong><Badge tone={item.stock === 0 ? "red" : "amber"}>{stockStatus(item)}</Badge></div></div>)}</div></section>
 <section className="panel activity-panel"><div className="panel-header"><div><h2>Aktivitas Terbaru</h2><p>Yang terjadi di workspace Anda</p></div><span className="live-label"><i className="status-dot"/> Hari ini</span></div><div className="activity-list">{[{icon:"check",tone:"mint",title:"Purchase order disetujui",detail:"PO-2026-0047 oleh Budi Santoso",time:"10 menit lalu"},{icon:"box",tone:"blue",title:"Penerimaan barang dikonfirmasi",detail:"GR-2026-0032 · 24 item diterima",time:"35 menit lalu"},{icon:"wallet",tone:"lavender",title:"Pembayaran berhasil diverifikasi",detail:"PAY-2026-0028 oleh Siti Rahayu",time:"1 jam lalu"}].map(a => <div className="activity" key={a.title}><span className={`activity-icon ${a.tone}`}><Icon name={a.icon} size={16}/></span><div><strong>{a.title}</strong><p>{a.detail}</p><small>{a.time}</small></div></div>)}</div></section></div>
 {create && <CreateOrderModal onClose={() => setCreate(false)}/>}</>;
}
