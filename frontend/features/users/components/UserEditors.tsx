"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { AccessChoices, AccessForm } from "./AccessForm";
import type { Account, Role } from "../types";

type EditorProps = { roles: Role[]; selfId: number; close: () => void; saved: (message: string) => void };

export function AccountEditor({ account, roles, selfId, close, saved }: EditorProps & { account?: Account }) {
  const [name, setName] = useState(account?.name ?? "");
  const [email, setEmail] = useState(account?.email ?? "");
  const [active, setActive] = useState(account?.is_active ?? true);
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [selected, setSelected] = useState<string[]>([]);
  const self = account?.id === selfId;
  const passwordRequired = !account || password !== "" || confirmation !== "";

  async function save() {
    const body = {
      name: name.trim(), email: email.trim(), is_active: active,
      ...(password ? { password, password_confirmation: confirmation } : {}),
      ...(account ? {} : { roles: selected }),
    };
    const { data } = await api<Account>(account ? "users/" + account.id : "users", {
      method: account ? "PATCH" : "POST", body,
    });
    if (self && (password || data.email !== account?.email)) {
      window.location.replace("/login");
      return;
    }
    saved(account ? "Perubahan akun tersimpan." : "Akun pengguna berhasil dibuat.");
  }

  return <AccessForm title={account ? "Edit Pengguna" : "Tambah Pengguna"} close={close} save={save}>
    <label className="editor-field"><span className="editor-label">Nama lengkap</span>
      <input required maxLength={255} autoComplete="off" value={name} onChange={event => setName(event.target.value)}/>
    </label>
    <label className="editor-field"><span className="editor-label">Email</span>
      <input type="email" required maxLength={255} autoComplete="off" value={email} onChange={event => setEmail(event.target.value)}/>
    </label>
    <label className="editor-field"><span className="editor-label">Status akun</span>
      <select value={active ? "active" : "inactive"} disabled={self} onChange={event => setActive(event.target.value === "active")}>
        <option value="active">Aktif</option><option value="inactive">Nonaktif</option>
      </select>
    </label>
    <div className="form-row">
      <label className="editor-field"><span className="editor-label">{account ? "Kata sandi baru" : "Kata sandi"}</span>
        <input type="password" autoComplete="new-password" minLength={12} maxLength={72} required={passwordRequired} value={password} onChange={event => setPassword(event.target.value)}/>
      </label>
      <label className="editor-field"><span className="editor-label">Konfirmasi kata sandi</span>
        <input type="password" autoComplete="new-password" minLength={12} maxLength={72} required={passwordRequired} value={confirmation} onChange={event => setConfirmation(event.target.value)}/>
      </label>
    </div>
    <p className="access-hint">{account ? "Isi kata sandi baru hanya jika ingin menggantinya. Perubahan email, kata sandi, atau status akan mengakhiri sesi akun." : "Kata sandi minimal 12 karakter. Akun perlu role atau tautan ke karyawan aktif untuk dapat masuk."}</p>
    {!account && <AccessChoices label="Role awal" options={roles} selected={selected} change={setSelected} limit={10}/>}
  </AccessForm>;
}

export function RoleAssignment({ account, roles, selfId, close, saved }: EditorProps & { account: Account }) {
  const [selected, setSelected] = useState(account.roles);
  const unchanged = selected.length === account.roles.length && selected.every(name => account.roles.includes(name));

  async function save() {
    await api("authorization/users/" + account.id + "/roles", { method: "PUT", body: { roles: selected } });
    if (account.id === selfId) {
      window.location.replace("/login");
      return;
    }
    saved("Role pengguna berhasil diperbarui.");
  }

  return <AccessForm title={"Atur role · " + account.name} close={close} save={save} disabled={unchanged}>
    <p className="access-hint">Perubahan role mengakhiri sesi akun ini. Akun tanpa role tetap bisa masuk melalui self-service jika terhubung ke karyawan aktif.</p>
    <AccessChoices label="Role pengguna" options={roles} selected={selected} change={setSelected} limit={10}/>
  </AccessForm>;
}
