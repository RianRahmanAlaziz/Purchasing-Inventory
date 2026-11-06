"use client";

import { Suspense, useState } from "react";
import { Sidebar } from "./Sidebar";
import { TopNav } from "./TopNav";
import { CompaniesProvider } from "@/features/companies/hooks/useCompanies";
import { Session } from "@/features/auth/Session";
import type { ApiUser } from "@/lib/api";

export function DashboardShell({ user, children }: { user: ApiUser; children: React.ReactNode }) {
  const [open, setOpen] = useState(false);
  return <Session value={user}><CompaniesProvider><div className="app-shell">
    <Suspense fallback={<aside className="sidebar"/>}>
      <Sidebar open={open} close={() => setOpen(false)}/>
    </Suspense>
    <div className="main-shell">
      <Suspense fallback={<header className="topnav"/>}><TopNav onMenu={() => setOpen(!open)}/></Suspense>
      <main className="page-content">{children}</main>
      <footer className="page-footer"><span>© 2026 RIS Purchasing</span><span className="footer-version">v1.0.0</span></footer>
    </div>
  </div></CompaniesProvider></Session>;
}
