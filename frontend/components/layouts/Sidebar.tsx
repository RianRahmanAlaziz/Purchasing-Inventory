"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Icon } from "@/components/ui/Icon";
import { visibleNavigation, workspaceHome } from "@/features/dashboard/constants/navigation";
import { useSession } from "@/features/auth/Session";

export function Sidebar({ open, close }: { open: boolean; close: () => void }) {
  const path = usePathname();
  const user = useSession();
  const groups = visibleNavigation(user);

  return <>
    <button className={"sidebar-overlay " + (open ? "visible" : "")} aria-label="Tutup navigasi" onClick={close}/>
    <aside className={"sidebar " + (open ? "open" : "")}>
      <Link href={workspaceHome(user)} className="brand" aria-label="RIS Purchasing">
        <span className="brand-mark"><Icon name="box" size={25}/></span><span className="brand-name"><strong>RIS</strong><small>Purchasing</small></span>
      </Link>
      <nav>{groups.map(group => <div className="nav-group" key={group.title}><p>{group.title}</p>
        {group.items.map(item => <Link onClick={close} className={"nav-link " + (path === "/" + item.slug ? "active" : "")}
          href={"/" + item.slug} key={item.slug}><Icon name={item.icon} size={19}/><span>{item.name}</span></Link>)}
      </div>)}</nav>
      <div className="sidebar-bottom">
        <Link href="/profile" className="nav-link" onClick={close}><Icon name="settings" size={19}/>Profil Saya</Link>
        <Link href="/help" className="nav-link" onClick={close}><Icon name="help" size={19}/>Pusat Bantuan</Link>
        <div className="sidebar-foot"><small>RIS Purchasing © 2026</small></div>
      </div>
    </aside>
  </>;
}
