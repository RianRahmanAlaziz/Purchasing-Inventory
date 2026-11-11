"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { Icon } from "@/components/ui/Icon";
import { useSession } from "@/features/auth/Session";
import { purchaseOrderHref, workspaceHome, type PurchaseOrderDestination } from "@/features/dashboard/constants/navigation";
import { useApiData } from "@/features/modules/hooks/useApiData";
import { api } from "@/lib/api";

type Notice = {
  id: string;
  purchase_order_id: number;
  purchase_order_number: string;
  title: string;
  message: string;
  target: PurchaseOrderDestination["slug"];
  reason: string | null;
  read_at: string | null;
  created_at: string;
};

type Inbox = {
  unread_count: number;
  notifications: Notice[];
  last_page: number;
};

const noticeDate = new Intl.DateTimeFormat("id-ID", {
  dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta",
});

export function NotificationMenu() {
  const user = useSession();
  const menu = useRef<HTMLDetailsElement>(null);
  const trigger = useRef<HTMLElement>(null);
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [marking, setMarking] = useState(false);
  const [markError, setMarkError] = useState("");
  const inbox = useApiData<Inbox>("notifications?page=" + page, revision);
  const unread = inbox.data?.unread_count ?? 0;

  useEffect(() => {
    function refreshWhenVisible() {
      if (!document.hidden) setRevision(current => current + 1);
    }

    function closeOnOutsidePress(event: PointerEvent) {
      const details = menu.current;
      if (details?.open && event.target instanceof Node && !details.contains(event.target)) {
        details.open = false;
      }
    }

    const timer = window.setInterval(refreshWhenVisible, 20_000);
    window.addEventListener("focus", refreshWhenVisible);
    document.addEventListener("visibilitychange", refreshWhenVisible);
    document.addEventListener("pointerdown", closeOnOutsidePress);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener("focus", refreshWhenVisible);
      document.removeEventListener("visibilitychange", refreshWhenVisible);
      document.removeEventListener("pointerdown", closeOnOutsidePress);
    };
  }, []);

  async function markRead(id?: string) {
    if (!id) setMarking(true);
    setMarkError("");
    try {
      await api(id ? "notifications/" + id + "/read" : "notifications/read-all", { method: "PATCH" });
      setRevision(current => current + 1);
    } catch (cause) {
      setMarkError(cause instanceof Error ? cause.message : "Notifikasi gagal ditandai dibaca.");
    } finally {
      if (!id) setMarking(false);
    }
  }

  return <details className="notification-wrap" ref={menu} onToggle={event => {
    if (event.currentTarget.open) {
      setPage(1);
      setRevision(current => current + 1);
    }
  }} onKeyDown={event => {
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
    <summary ref={trigger} className="icon-button notification-button"
      aria-label={unread ? "Notifikasi, " + unread + " belum dibaca" : "Notifikasi"}>
      <Icon name="bell" size={18}/>
      {unread > 0 && <span className="notification-count" aria-hidden="true">{unread > 99 ? "99+" : unread}</span>}
    </summary>
    <div className="notification-panel" role="region" aria-label="Daftar notifikasi">
      <header className="notification-heading">
        <strong>Notifikasi</strong>
        {unread > 0 && <button type="button" className="notification-text-button" disabled={marking}
          onClick={() => void markRead()}>Tandai semua dibaca</button>}
      </header>
      {markError && <p className="notification-message form-error" role="alert">{markError}</p>}
      {inbox.error ? <p className="notification-message form-error" role="alert">{inbox.error}</p>
        : inbox.loading && !inbox.data ? <p className="notification-message" role="status">Memuat notifikasi…</p>
        : inbox.data?.notifications.length ? <ul className="notification-list">
          {inbox.data.notifications.map(notice => <li key={notice.id}>
            <Link className={notice.read_at ? "notification-entry" : "notification-entry is-unread"}
              href={purchaseOrderHref(user, { slug: notice.target, id: notice.purchase_order_id }) ?? workspaceHome(user)} prefetch={false}
              onClick={() => {
                if (menu.current) menu.current.open = false;
                if (!notice.read_at) void markRead(notice.id);
              }}>
              {notice.target === "approvals" ? <>
                <strong>Menunggu persetujuan</strong>
                <span>{notice.purchase_order_number}</span>
              </> : <>
                <strong>{notice.title}</strong>
                <span>{notice.message}</span>
                {notice.reason && <span className="notification-reason">{notice.reason}</span>}
              </>}
              <time dateTime={notice.created_at}>{noticeDate.format(new Date(notice.created_at))}</time>
            </Link>
          </li>)}
        </ul> : <p className="notification-message">Belum ada notifikasi.</p>}
      {inbox.data && inbox.data.last_page > 1 && <footer className="notification-pages">
        <button type="button" className="notification-text-button" disabled={page <= 1}
          onClick={() => setPage(current => current - 1)}>Sebelumnya</button>
        <span>{page} / {inbox.data.last_page}</span>
        <button type="button" className="notification-text-button" disabled={page >= inbox.data.last_page}
          onClick={() => setPage(current => current + 1)}>Berikutnya</button>
      </footer>}
    </div>
  </details>;
}
