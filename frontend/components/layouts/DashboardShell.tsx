"use client";
import { Suspense, useState } from "react";
import { Sidebar } from "./Sidebar";
import { TopNav } from "./TopNav";
import { OrdersProvider } from "@/features/purchase-orders/hooks/useOrders";
import { CompaniesProvider } from "@/features/companies/hooks/useCompanies";
import { ItemsProvider } from "@/features/items/hooks/useItems";
export function DashboardShell({ children }: { children: React.ReactNode }) {
 const [open, setOpen] = useState(false);
 return <CompaniesProvider><ItemsProvider><OrdersProvider><div className="app-shell"><Suspense fallback={<aside className="sidebar"/>}><Sidebar open={open} close={() => setOpen(false)}/></Suspense><div className="main-shell"><Suspense fallback={<header className="topnav"/>}><TopNav onMenu={() => setOpen(!open)}/></Suspense><main className="page-content">{children}</main><footer className="page-footer"><span>© 2026 RIS Purchasing. Kelola lebih mudah, tumbuh lebih jauh.</span><span><i className="status-dot"/> Workspace aktif <span className="footer-version">v1.0.0</span></span></footer></div></div></OrdersProvider></ItemsProvider></CompaniesProvider>;
}
