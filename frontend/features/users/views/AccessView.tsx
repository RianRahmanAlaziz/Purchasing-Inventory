"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { useSession } from "@/features/auth/Session";
import { useApiData } from "@/features/modules/hooks/useApiData";
import { api } from "@/lib/api";
import { AccessChoices, AccessForm } from "../components/AccessForm";
import type { AuditEntry, Permission, Role } from "../types";

type EditorProps = { close: () => void; saved: (message: string) => void };

function RoleEditor({ role, permissions, close, saved }: EditorProps & { role?: Role; permissions: Permission[] }) {
  const [name, setName] = useState(role?.name ?? "");
  const [selected, setSelected] = useState(role?.permissions ?? []);

  async function save() {
    await api(role ? "authorization/roles/" + role.id : "authorization/roles", {
      method: role ? "PATCH" : "POST", body: { name: name.trim(), permissions: selected },
    });
    saved("Role berhasil disimpan.");
  }

  return <AccessForm title={role ? "Edit Role" : "Tambah Role"} disabled={!role && !selected.length} save={save} close={close}>
    <label className="editor-field"><span className="editor-label">Nama role</span>
      <input required maxLength={100} pattern="[a-z][a-z0-9_]*" value={name} placeholder="contoh: petugas_gudang" onChange={event => setName(event.target.value)}/>
    </label>
    <p className="access-hint">Gunakan huruf kecil, angka, atau garis bawah. Perubahan izin berlaku untuk semua pengguna dengan role ini.</p>
    <AccessChoices label="Izin role" options={permissions} selected={selected} change={setSelected} searchable/>
  </AccessForm>;
}

function PermissionEditor({ permission, close, saved }: EditorProps & { permission?: Permission }) {
  const [name, setName] = useState(permission?.name ?? "");
  const [displayName, setDisplayName] = useState(permission?.display_name ?? "");
  const [description, setDescription] = useState(permission?.description ?? "");

  async function save() {
    await api(permission ? "authorization/permissions/" + permission.id : "authorization/permissions", {
      method: permission ? "PATCH" : "POST",
      body: {
        ...(permission ? {} : { name: name.trim() }),
        display_name: displayName.trim() || null,
        description: description.trim() || null,
      },
    });
    saved("Izin berhasil disimpan.");
  }

  return <AccessForm title={permission ? "Edit Izin" : "Tambah Izin"} save={save} close={close}>
    <label className="editor-field"><span className="editor-label">Kode izin</span>
      <input required readOnly={!!permission} maxLength={125} pattern={"[a-z][a-z0-9_.\\-]*"} value={name} placeholder="contoh: inventory.view" onChange={event => setName(event.target.value)}/>
    </label>
    <label className="editor-field"><span className="editor-label">Nama tampilan</span>
      <input maxLength={150} value={displayName} onChange={event => setDisplayName(event.target.value)}/>
    </label>
    <label className="editor-field"><span className="editor-label">Deskripsi</span>
      <textarea rows={3} maxLength={1000} value={description} onChange={event => setDescription(event.target.value)}/>
    </label>
    <p className="access-hint">Kode izin digunakan oleh aksi yang memeriksa kode tersebut.</p>
  </AccessForm>;
}

const eventNames: Record<string, string> = {
  "user.created": "Pengguna dibuat", "user.updated": "Akun diperbarui", "user.roles.synced": "Role pengguna diubah",
  "role.created": "Role dibuat", "role.updated": "Role diperbarui",
  "permission.created": "Izin dibuat", "permission.updated": "Izin diperbarui",
};

function formatAudit(value: string | null) {
  if (!value) return "—";
  try { return JSON.stringify(JSON.parse(value), null, 2); }
  catch { return value; }
}

function AuditTable({ revision }: { revision: number }) {
  const [page, setPage] = useState(1);
  const log = useApiData<AuditEntry[]>("authorization/audit-logs?page=" + page, revision);
  if (log.loading) return <p className="loading-state" role="status">Memuat riwayat…</p>;
  if (log.error) return <p className="form-error" role="alert">{log.error}</p>;
  return <>
    {log.data?.length ? <div className="table-scroll"><table>
      <thead><tr><th>Waktu</th><th>Tindakan</th><th>Pelaku</th><th>Objek</th><th>Detail</th></tr></thead>
      <tbody>{log.data.map(entry => <tr key={entry.id}>
        <td>{entry.created_at}</td><td>{eventNames[entry.event] ?? entry.event}</td>
        <td>{entry.actor_user_id === null ? "—" : "#" + entry.actor_user_id}</td>
        <td>{entry.subject_type} #{entry.subject_id}</td>
        <td><details className="access-audit-detail"><summary>Lihat perubahan</summary>
          <p>Sebelum</p><pre>{formatAudit(entry.before_data)}</pre>
          <p>Sesudah</p><pre>{formatAudit(entry.after_data)}</pre>
        </details></td>
      </tr>)}</tbody>
    </table></div> : <p className="loading-state">Belum ada perubahan yang tercatat.</p>}
    <div className="access-audit-pages">
      <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(current => current - 1)}>Sebelumnya</Button>
      <span>Halaman {page} dari {log.lastPage ?? 1}</span>
      <Button variant="secondary" disabled={page >= (log.lastPage ?? 1)} onClick={() => setPage(current => current + 1)}>Berikutnya</Button>
    </div>
  </>;
}

type Selection = { mode: "role"; role?: Role } | { mode: "permission"; permission?: Permission };
const tabs = [{ id: "roles", label: "Role" }, { id: "permissions", label: "Izin" }, { id: "audit", label: "Audit" }] as const;

export function AccessView() {
  const user = useSession();
  const router = useRouter();
  const allowed = user.permissions.includes("roles.manage");
  const [tab, setTab] = useState<typeof tabs[number]["id"]>("roles");
  const [revision, setRevision] = useState(0);
  const roles = useApiData<Role[]>(allowed ? "authorization/roles" : null, revision);
  const permissions = useApiData<Permission[]>(allowed ? "authorization/permissions" : null, revision);
  const [selection, setSelection] = useState<Selection | null>(null);
  const [notice, setNotice] = useState("");
  const [query, setQuery] = useState("");
  const search = query.trim().toLowerCase();
  const filteredRoles = roles.data?.filter(role => role.name.toLowerCase().includes(search));
  const filteredPermissions = permissions.data?.filter(permission =>
    [permission.name, permission.display_name].some(value => value?.toLowerCase().includes(search)));
  const current = tab === "roles" ? roles : permissions;

  function edit(next: Selection) {
    setSelection(next);
    setNotice("");
  }

  function saved(message: string) {
    setSelection(null);
    setNotice(message);
    setRevision(current => current + 1);
    router.refresh();
  }

  if (!allowed) return <div className="page-heading"><div><h1>Role &amp; Izin</h1><p role="alert">Akun Anda tidak memiliki izin mengelola role.</p></div></div>;

  return <>
    <div className="page-heading">
      <div><div className="eyebrow"><span className="status-dot"/> ADMINISTRASI</div><h1>Role &amp; Izin</h1><p>Atur izin setiap role dan telusuri riwayat perubahannya.</p></div>
      <div className="heading-actions">
        {tab === "roles" && <Button disabled={!roles.data || roles.loading || !permissions.data || permissions.loading} onClick={() => edit({ mode: "role" })}><Icon name="plus" size={17}/> Tambah Role</Button>}
        {tab === "permissions" && <Button disabled={!permissions.data || permissions.loading} onClick={() => edit({ mode: "permission" })}><Icon name="plus" size={17}/> Tambah Izin</Button>}
      </div>
    </div>
    {notice && <p className="form-hint" role="status">{notice}</p>}
    {tab === "roles" && permissions.error && <p className="form-error" role="alert">{permissions.error}</p>}
    <section className="panel module-panel">
      <div className="panel-header">
        <div className="access-tabs" role="group" aria-label="Bagian pengelolaan akses">{tabs.map(item =>
          <Button key={item.id} variant={tab === item.id ? "primary" : "secondary"} aria-pressed={tab === item.id}
            onClick={() => { setTab(item.id); setQuery(""); }}>{item.label}</Button>)}</div>
        <Button variant="secondary" onClick={() => setRevision(current => current + 1)}>Muat ulang</Button>
      </div>
      {tab !== "audit" && <div className="table-toolbar"><label className="table-search"><Icon name="search" size={18}/>
        <input aria-label={tab === "roles" ? "Cari role" : "Cari izin"} placeholder="Cari…" value={query} onChange={event => setQuery(event.target.value)}/>
      </label></div>}
      {tab === "audit" ? <AuditTable revision={revision}/>
        : current.loading ? <p className="loading-state" role="status">Memuat data…</p>
        : current.error ? <p className="form-error" role="alert">{current.error}</p>
        : tab === "roles" && filteredRoles?.length ? <div className="table-scroll"><table>
          <thead><tr><th>Nama role</th><th>Jumlah izin</th><th>Aksi</th></tr></thead>
          <tbody>{filteredRoles.map(role => <tr key={role.id}><td><strong>{role.name}</strong></td>
            <td>{role.permissions.length}</td><td><Button variant="secondary" aria-label={"Edit role " + role.name} disabled={!permissions.data || permissions.loading} onClick={() => edit({ mode: "role", role })}>Edit</Button></td>
          </tr>)}</tbody>
        </table></div>
        : tab === "permissions" && filteredPermissions?.length ? <div className="table-scroll"><table>
          <thead><tr><th>Kode izin</th><th>Nama tampilan</th><th>Deskripsi</th><th>Aksi</th></tr></thead>
          <tbody>{filteredPermissions.map(permission => <tr key={permission.id}><td><strong>{permission.name}</strong></td><td>{permission.display_name ?? "—"}</td>
            <td>{permission.description ?? "—"}</td><td><Button variant="secondary" aria-label={"Edit izin " + permission.name} onClick={() => edit({ mode: "permission", permission })}>Edit</Button></td>
          </tr>)}</tbody>
        </table></div>
        : <div className="empty-state"><Icon name="settings" size={30}/><h3>Data tidak ditemukan</h3><p>Ubah pencarian atau tambahkan data baru.</p></div>}
    </section>
    {selection?.mode === "role" && <RoleEditor role={selection.role} permissions={permissions.data ?? []} close={() => setSelection(null)} saved={saved}/>}
    {selection?.mode === "permission" && <PermissionEditor permission={selection.permission} close={() => setSelection(null)} saved={saved}/>}
  </>;
}
