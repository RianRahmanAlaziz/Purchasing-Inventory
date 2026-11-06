"use client";

import { useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ExportButton } from "@/components/ui/ExportButton";
import { Icon } from "@/components/ui/Icon";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import { useSession } from "@/features/auth/Session";
import { datePart } from "@/features/modules/api-types";
import { useApiData } from "@/features/modules/hooks/useApiData";
import { AccountEditor, RoleAssignment } from "../components/UserEditors";
import type { Account, Role } from "../types";

type Selection = { mode: "create" } | { mode: "edit" | "roles"; account: Account };
const columns = ["ID", "Nama", "Email", "Role", "Status", "ID Karyawan"];

export function UsersView() {
  const user = useSession();
  const router = useRouter();
  const params = useSearchParams();
  const canView = user.permissions.includes("users.view");
  const canAssign = user.permissions.includes("roles.manage");
  const canEdit = canAssign && user.permissions.includes("users.manage");
  const [revision, setRevision] = useState(0);
  const accounts = useApiData<Account[]>(canView ? "users" : null, revision, true);
  const roles = useApiData<Role[]>(canView && canAssign ? "authorization/roles" : null, revision);
  const [query, setQuery] = useState(params.get("q") ?? "");
  const [status, setStatus] = useState("");
  const [selection, setSelection] = useState<Selection | null>(null);
  const [notice, setNotice] = useState("");
  const filtered = (accounts.data ?? []).filter(account =>
    (!status || String(account.is_active) === status)
    && [account.name, account.email, ...account.roles].join(" ").toLowerCase().includes(query.trim().toLowerCase()));
  const pagination = usePagination(filtered, JSON.stringify([query, status]));

  function open(next: Selection) {
    setNotice("");
    setSelection(next);
  }

  function saved(message: string) {
    setSelection(null);
    setNotice(message);
    setRevision(current => current + 1);
    router.refresh();
  }

  if (!canView) return <div className="page-heading"><div><h1>Pengguna</h1><p role="alert">Akun Anda tidak memiliki izin melihat daftar pengguna.</p></div></div>;

  return <>
    <div className="page-heading">
      <div><div className="eyebrow"><span className="status-dot"/> ADMINISTRASI</div><h1>Pengguna</h1><p>Kelola akun, status akses, dan role pengguna.</p></div>
      <div className="heading-actions">
        <ExportButton filename="pengguna.csv" columns={[...columns, "Tanggal dibuat"]} disabled={!accounts.data || accounts.loading}
          records={filtered.map(account => ({
            date: datePart(account.created_at),
            cells: [account.id, account.name, account.email, account.roles.join(", "), account.is_active ? "Aktif" : "Nonaktif", account.employee_id ?? "", datePart(account.created_at)],
          }))}/>
        {canEdit && <Button disabled={accounts.loading || !roles.data || roles.loading} onClick={() => open({ mode: "create" })}><Icon name="plus" size={17}/> Tambah Pengguna</Button>}
      </div>
    </div>
    {notice && <p className="form-hint" role="status">{notice}</p>}
    {roles.error && <p className="form-error" role="alert">{roles.error}</p>}
    <section className="panel module-panel">
      <div className="panel-header"><h2>Daftar Pengguna <span className="count-pill">{filtered.length}</span></h2>
        <Button variant="secondary" disabled={accounts.loading} onClick={() => setRevision(current => current + 1)}>Muat ulang</Button>
      </div>
      <div className="table-toolbar">
        <label className="table-search"><Icon name="search" size={18}/><input aria-label="Cari pengguna" placeholder="Cari nama, email, atau role…" value={query} onChange={event => setQuery(event.target.value)}/></label>
        <select aria-label="Filter status akun" value={status} onChange={event => setStatus(event.target.value)}>
          <option value="">Semua status</option><option value="true">Aktif</option><option value="false">Nonaktif</option>
        </select>
      </div>
      {accounts.loading ? <p className="loading-state" role="status">Memuat pengguna…</p>
        : accounts.error ? <p className="form-error" role="alert">{accounts.error}</p>
        : filtered.length ? <>
          <div className="table-scroll"><table>
            <thead><tr>{columns.map(column => <th key={column}>{column}</th>)}{canAssign && <th>Aksi</th>}</tr></thead>
            <tbody>{pagination.items.map(account => <tr key={account.id}>
              <td>#{account.id}</td>
              <td><strong>{account.name}</strong>{account.id === user.id && <span className="access-self">Anda</span>}</td>
              <td>{account.email}</td>
              <td>{account.roles.join(", ") || "—"}</td>
              <td><Badge tone={account.is_active ? "green" : "gray"}>{account.is_active ? "Aktif" : "Nonaktif"}</Badge></td>
              <td>{account.employee_id ?? "—"}</td>
              {canAssign && <td><div className="access-row-actions">
                {canEdit && <Button variant="secondary" aria-label={"Edit " + account.name} onClick={() => open({ mode: "edit", account })}>Edit</Button>}
                <Button variant="secondary" aria-label={"Atur role " + account.name} disabled={!roles.data || roles.loading} onClick={() => open({ mode: "roles", account })}>Atur role</Button>
              </div></td>}
            </tr>)}</tbody>
          </table></div>
          <Pagination {...pagination} label="pengguna"/>
        </> : <div className="empty-state"><Icon name="users" size={30}/><h3>Pengguna tidak ditemukan</h3><p>Data yang cocok dengan filter akan tampil di sini.</p></div>}
    </section>
    {canEdit && selection?.mode === "create" && <AccountEditor roles={roles.data ?? []} selfId={user.id} close={() => setSelection(null)} saved={saved}/>}
    {canEdit && selection?.mode === "edit" && <AccountEditor account={selection.account} roles={[]} selfId={user.id} close={() => setSelection(null)} saved={saved}/>}
    {canAssign && selection?.mode === "roles" && <RoleAssignment account={selection.account} roles={roles.data ?? []} selfId={user.id} close={() => setSelection(null)} saved={saved}/>}
  </>;
}
