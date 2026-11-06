"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Icon } from "@/components/ui/Icon";
import { navigation } from "@/features/dashboard/constants/navigation";
import { useSession } from "@/features/auth/Session";
import { api } from "@/lib/api";

export function TopNav({ onMenu }: { onMenu: () => void }) {
  const path = usePathname();
  const user = useSession();
  const menu = useRef<HTMLDetailsElement>(null);
  const trigger = useRef<HTMLElement>(null);
  const title = navigation.flatMap(group => group.items).find(item => "/" + item.slug === path)?.name
    || (path === "/profile" ? "Profil Saya" : "Pusat Bantuan");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    function closeOnOutsidePress(event: PointerEvent) {
      const details = menu.current;
      if (details?.open && event.target instanceof Node && !details.contains(event.target)) {
        details.open = false;
      }
    }

    document.addEventListener("pointerdown", closeOnOutsidePress);
    return () => document.removeEventListener("pointerdown", closeOnOutsidePress);
  }, []);

  async function logout() {
    setBusy(true);
    setError("");
    try {
      await api("auth/logout", { method: "POST" });
      window.location.replace("/login");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Gagal keluar. Coba lagi.");
      setBusy(false);
    }
  }

  return <header className="topnav">
    <div className="breadcrumb"><button className="icon-button mobile-menu" onClick={onMenu} aria-label="Buka menu"><Icon name="menu"/></button><span>Workspace</span><Icon name="chevron" size={14}/><strong>{title}</strong></div>
    <div className="topnav-actions">
      <details className="account-menu" ref={menu} onKeyDown={event => {
        if (event.key === "Escape" && event.currentTarget.open) {
          event.preventDefault();
          event.currentTarget.open = false;
          trigger.current?.focus();
        }
      }} onBlur={event => {
        if (event.relatedTarget && !event.currentTarget.contains(event.relatedTarget)) {
          event.currentTarget.open = false;
        }
      }}>
        <summary className="account-trigger" ref={trigger} aria-label={"Menu akun " + user.name}>
          <span className="avatar" aria-hidden="true">{user.name.slice(0, 2).toUpperCase()}</span>
          <strong className="account-name">{user.name}</strong>
          <Icon name="down" size={15}/>
        </summary>
        <div className="account-dropdown">
          <Link href="/profile" className="account-option" onClick={() => { if (menu.current) menu.current.open = false; }}>
            <Icon name="users" size={17}/> Profil Saya
          </Link>
          <button type="button" className="account-option account-logout" disabled={busy} onClick={() => void logout()}>
            <Icon name="logout" size={17}/> {busy ? "Sedang keluar..." : "Keluar"}
          </button>
          {error && <p className="account-error" role="alert">{error}</p>}
        </div>
      </details>
    </div>
  </header>;
}
