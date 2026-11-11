import type { Metadata } from "next";
import { Suspense } from "react";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { LoginView } from "@/features/auth/views/LoginView";
import { purchaseOrderHref, workspaceHome, type PurchaseOrderDestination } from "@/features/dashboard/constants/navigation";
import type { ApiUser } from "@/lib/api";
import { backendRequest, sessionCookie } from "@/lib/backend";

export const metadata: Metadata = { title: "Masuk | RIS Purchasing" };

type LoginProps = { searchParams: Promise<{ next?: string | string[] }> };

async function LoginScreen({ searchParams }: LoginProps) {
  const next = (await searchParams).next;
  const link = typeof next === "string" ? /^\/(purchase-orders|approvals)\?po=([1-9]\d{0,14})$/.exec(next) : null;
  if (!link) return <LoginView/>;

  const destination: PurchaseOrderDestination = { slug: link[1] === "approvals" ? "approvals" : "purchase-orders", id: link[2] };
  const token = (await cookies()).get(sessionCookie)?.value;
  if (token) {
    const response = await backendRequest("auth/me", {}, token).catch(() => null);
    const payload = response?.ok ? await response.json().catch(() => null) : null;
    const user: ApiUser | undefined = payload?.data?.user;
    if (user) redirect(purchaseOrderHref(user, destination) ?? workspaceHome(user));
  }

  return <LoginView destination={destination}/>;
}

export default function LoginPage(props: LoginProps) {
  return <Suspense fallback={<main className="workspace-loading"><p className="loading-state" role="status">Memeriksa sesi…</p></main>}>
    <LoginScreen {...props}/>
  </Suspense>;
}
