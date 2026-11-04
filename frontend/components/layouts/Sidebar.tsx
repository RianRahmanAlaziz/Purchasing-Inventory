"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Icon } from "@/components/ui/Icon";
import { navigation } from "@/features/dashboard/constants/navigation";
export function Sidebar({ open, close }: { open: boolean; close: () => void }) {
  const path = usePathname();
  return <><button className={`sidebar-overlay ${open ? "visible" : ""}`} aria-label="Tutup navigasi" onClick={close}/><aside className={`sidebar ${open ? "open" : ""}`}>
    <Link href="/dashboard" className="brand" aria-label="RIS Purchasing"><span className="brand-mark"><Icon name="box" size={25}/></span><span className="brand-name"><strong>RIS</strong><small>Purchasing</small></span></Link>
    <nav>{navigation.map(group => <div className="nav-group" key={group.title}><p>{group.title}</p>{group.items.filter(item => !["payments", "receipts"].includes(item.slug)).map(item => <Link onClick={close} className={`nav-link ${path === `/${item.slug}` ? "active" : ""}`} href={`/${item.slug}`} key={item.slug}><Icon name={item.icon} size={19}/><span>{item.name}</span>{item.slug === "purchase-orders" && <span className="nav-count">5</span>}</Link>)}</div>)}</nav>
    <div className="sidebar-bottom"><Link href="/profile" className="nav-link"><Icon name="settings" size={19}/>Pengaturan</Link><Link href="/help" className="nav-link"><Icon name="help" size={19}/>Pusat Bantuan<span className="external-arrow">&#8599;</span></Link><div className="sidebar-foot"><span className="status-dot"/>Semua sistem beroperasi<small>RIS Purchasing © 2026</small></div></div>
  </aside></>;
}
