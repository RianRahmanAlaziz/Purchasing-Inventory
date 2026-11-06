import { Suspense } from "react";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { DashboardShell } from "@/components/layouts/DashboardShell";
import { backendRequest, sessionCookie } from "@/lib/backend";
import type { ApiUser } from "@/lib/api";

async function Workspace({ children }: { children: React.ReactNode }) {
  const token = (await cookies()).get(sessionCookie)?.value;
  if (!token) redirect("/login");
  const response = await backendRequest("auth/me", {}, token).catch(() => null);
  if (response?.status === 401 || response?.status === 403) redirect("/login");
  const payload = response?.ok ? await response.json().catch(() => null) : null;
  const user: ApiUser | undefined = payload?.data?.user;
  if (!user) return <main className="page-content"><h1>Workspace belum dapat dibuka</h1><p>Periksa koneksi ke Laravel, lalu muat ulang halaman.</p></main>;
  return <DashboardShell user={user}>{children}</DashboardShell>;
}

export default function CmsLayout({ children }: { children: React.ReactNode }) {
  return <Suspense fallback={<p className="loading-state">Memeriksa sesi...</p>}><Workspace>{children}</Workspace></Suspense>;
}
