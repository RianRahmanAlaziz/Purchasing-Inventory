"use client";

import { useSession } from "@/features/auth/Session";

export default function ProfilePage() {
  const user = useSession();
  return <>
    <div className="page-heading"><div><div className="eyebrow">WORKSPACE / AKUN</div><h1>Profil Saya</h1><p>Informasi akun workspace.</p></div></div>
    <section className="panel profile-panel">
      <span className="avatar large">{user.name.slice(0, 2).toUpperCase()}</span><h2>{user.name}</h2>
      <div className="detail-grid"><p>Email<strong>{user.email}</strong></p><p>Peran<strong>{user.roles.join(", ") || "Karyawan"}</strong></p></div>
    </section>
  </>;
}
