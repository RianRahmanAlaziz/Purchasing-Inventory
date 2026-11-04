"use client";

import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import { ExportButton } from "@/components/ui/ExportButton";
import { useUsers } from "../hooks/useUsers";
import { roles, statuses, type User } from "../utils/users";

const columns = ["ID Pengguna", "Nama", "Email", "Peran", "Status"];
const values = (user: User) => [user.id, user.name, user.email, user.role, user.status];

function UserForm({ initial, editing, error, save, close }: {
  initial: User; editing: boolean; error: string;
  save: (user: User, editing: boolean) => boolean; close: () => void;
}) {
  const [user, setUser] = useState(initial);
  return <Modal title={editing ? "Edit Pengguna" : "Tambah Pengguna"} onClose={close}>
    <form className="order-form company-form" onSubmit={event => { event.preventDefault(); if (save(user, editing)) close(); }}>
      <label>Nama<input autoFocus required maxLength={120} value={user.name} onChange={event => setUser({ ...user, name: event.target.value })} placeholder="Nama lengkap"/></label>
      <label>Email<input required type="email" maxLength={120} value={user.email} onChange={event => setUser({ ...user, email: event.target.value })} placeholder="nama@perusahaan.co.id"/></label>
      <div className="form-row">
        <label>Peran<select value={user.role} onChange={event => setUser({ ...user, role: event.target.value as User["role"] })}>{roles.map(role => <option key={role}>{role}</option>)}</select></label>
        <label>Status<select value={user.status} onChange={event => setUser({ ...user, status: event.target.value as User["status"] })}>{statuses.map(status => <option key={status}>{status}</option>)}</select></label>
      </div>
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="modal-actions"><Button type="button" variant="secondary" onClick={close}>Batal</Button><Button type="submit">Simpan Pengguna</Button></div>
    </form>
  </Modal>;
}

export function UsersView() {
  const params = useSearchParams();
  const { users, ready, error, clearError, save, remove } = useUsers();
  const [query, setQuery] = useState(params.get("q") || "");
  const [status, setStatus] = useState(params.get("status") || "Semua status");
  const [selection, setSelection] = useState<{ mode: "create" | "view" | "edit" | "delete"; user: User } | null>(null);
  const [notice, setNotice] = useState("");
  const filtered = users.filter(user => values(user).join(" ").toLowerCase().includes(query.toLowerCase()) && (status === "Semua status" || user.status === status));
  const pagination = usePagination(filtered, JSON.stringify([query, status]));
  function open(mode: NonNullable<typeof selection>["mode"], user: User) { clearError(); setNotice(""); setSelection({ mode, user }); }
  function close() { clearError(); setSelection(null); }
  return <>
    <div className="page-heading"><div><div className="eyebrow"><span className="status-dot"/> WORKSPACE / MANAGEMENT</div><h1>Pengguna</h1><p>Kelola anggota workspace, peran, dan status pengguna.</p></div>
      <div className="heading-actions"><ExportButton filename="users.csv" disabled={!ready} dateLabel="Tanggal ditambahkan" columns={[...columns, "Tanggal ditambahkan"]} records={filtered.map(user => ({ date: user.createdAt, cells: [...values(user), user.createdAt || ""] }))}/>
        <Button disabled={!ready} onClick={() => open("create", { id: `USR-${crypto.randomUUID()}`, name: "", email: "", role: "Employee", status: "Aktif" })}><Icon name="plus" size={17}/> Tambah Pengguna</Button></div>
    </div>
    <p className="form-hint">Data pengguna disimpan di browser ini. Peran dan status belum terhubung dengan autentikasi atau hak akses server.</p>
    {notice && <p role="status" className="form-hint">{notice}</p>}
    {error && !selection && <p role="alert" className="form-error">{error}</p>}
    <section className="panel module-panel"><div className="panel-header"><div><h2>Daftar Pengguna <span className="count-pill">{filtered.length}</span></h2><p>Kelola informasi anggota workspace</p></div></div>
      <div className="table-toolbar"><label className="table-search"><Icon name="search" size={18}/><input aria-label="Cari Pengguna" placeholder="Cari pengguna..." value={query} onChange={event => setQuery(event.target.value)}/></label>
        <select aria-label="Filter status" value={status} onChange={event => setStatus(event.target.value)}><option>Semua status</option>{statuses.map(item => <option key={item}>{item}</option>)}</select></div>
      <div className="table-scroll"><table><thead><tr>{columns.map(column => <th key={column}>{column}</th>)}<th>Aksi</th></tr></thead><tbody>{pagination.items.map(user => <tr key={user.id}>
        <td><button className="table-link" onClick={() => open("view", user)}>{user.id}</button></td><td>{user.name}</td><td>{user.email}</td><td>{user.role}</td><td><Badge tone={user.status === "Aktif" ? "green" : "gray"}>{user.status}</Badge></td>
        <td><div className="po-row-actions">
          <button className="icon-button po-action-view" aria-label={`Lihat ${user.name}`} title="Lihat pengguna" onClick={() => open("view", user)}><Icon name="eye" size={17}/></button>
          <button className="icon-button po-action-edit" aria-label={`Edit ${user.name}`} title="Edit pengguna" disabled={!ready} onClick={() => open("edit", user)}><Icon name="edit" size={17}/></button>
          <button className="icon-button po-action-delete" aria-label={`Hapus ${user.name}`} title="Hapus pengguna" disabled={!ready} onClick={() => open("delete", user)}><Icon name="trash" size={17}/></button>
        </div></td>
      </tr>)}</tbody></table>{filtered.length === 0 && <div className="empty-state"><Icon name="users" size={32}/><h3>Pengguna tidak ditemukan</h3><p>Tambahkan pengguna atau coba kata kunci dan status lainnya.</p></div>}</div>
      <Pagination {...pagination} label="pengguna"/>
    </section>
    {selection && (selection.mode === "create" || selection.mode === "edit") && <UserForm key={selection.user.id} initial={selection.user} editing={selection.mode === "edit"} error={error} close={close} save={(user, editing) => { const saved = save(user, editing); if (saved) setNotice(editing ? "Perubahan pengguna berhasil disimpan." : "Pengguna berhasil ditambahkan."); return saved; }}/>}
    {selection?.mode === "view" && <Modal title="Detail Pengguna" onClose={close}><div className="detail-grid">{values(selection.user).map((value, index) => <p key={columns[index]}>{columns[index]}<strong>{value}</strong></p>)}</div><div className="modal-actions"><Button variant="secondary" onClick={close}>Tutup</Button><Button disabled={!ready} onClick={() => open("edit", selection.user)}>Edit Pengguna</Button></div></Modal>}
    {selection?.mode === "delete" && <Modal title="Hapus Pengguna?" onClose={close}>
      <div className="po-delete-notice"><span><Icon name="trash" size={25}/></span><p>Pengguna <strong>{selection.user.name}</strong> ({selection.user.email}) akan dihapus. Tindakan ini tidak dapat dibatalkan.</p></div>
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="modal-actions"><Button variant="secondary" autoFocus onClick={close}>Batal</Button><Button className="danger" disabled={!ready} onClick={() => { if (remove(selection.user.id)) { setNotice("Pengguna berhasil dihapus."); close(); } }}>Ya, Hapus Pengguna</Button></div>
    </Modal>}
  </>;
}
