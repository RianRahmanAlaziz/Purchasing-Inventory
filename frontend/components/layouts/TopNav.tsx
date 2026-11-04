"use client";
import { useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { Icon } from "@/components/ui/Icon";
import { navigation } from "@/features/dashboard/constants/navigation";
export function TopNav({ onMenu }: { onMenu: () => void }) {
  const path = usePathname(); const router = useRouter();
  const title = navigation.flatMap(g => g.items).find(i => `/${i.slug}` === path)?.name || (path === "/profile" ? "Profil Saya" : "Pusat Bantuan");
  const [notifications, setNotifications] = useState(false); const [query, setQuery] = useState("");
  return <header className="topnav"><div className="breadcrumb"><button className="icon-button mobile-menu" onClick={onMenu} aria-label="Buka menu"><Icon name="menu"/></button><span>Workspace</span><Icon name="chevron" size={14}/><strong>{title}</strong></div><div className="topnav-actions"><form className="global-search" onSubmit={e => { e.preventDefault(); router.push(`/purchase-orders?q=${encodeURIComponent(query)}`); }}><Icon name="search" size={17}/><input aria-label="Cari purchase order" placeholder="Cari sesuatu..." value={query} onChange={e => setQuery(e.target.value)}/><kbd>Enter</kbd></form><div className="notification-wrap"><button aria-label="Notifikasi" aria-expanded={notifications} className="icon-button notification-button" onClick={() => setNotifications(!notifications)}><Icon name="bell"/><i/></button>{notifications && <div className="notification-panel"><strong>Notifikasi</strong><Link href="/purchase-orders" onClick={() => setNotifications(false)}>PO-2026-0048 menunggu persetujuan<small>Purchase Order · Hari ini</small></Link><Link href="/items" onClick={() => setNotifications(false)}>4 barang memiliki stok menipis<small>Inventory · Hari ini</small></Link></div>}</div><span className="topnav-divider"/><Link href="/profile" className="profile-link"><span className="avatar">AP</span><div><strong>Andi Pratama</strong><small>Administrator</small></div><Icon name="down" size={14}/></Link><button type="button" className="logout-button" aria-label="Keluar dari workspace" title="Keluar" onClick={() => router.replace("/login")}><Icon name="logout" size={18}/><span>Keluar</span></button></div></header>;
}
