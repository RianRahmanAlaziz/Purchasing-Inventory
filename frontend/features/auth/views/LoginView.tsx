"use client";

import { useState, type FormEvent } from "react";
import Link from "next/link";
import { Icon } from "@/components/ui/Icon";
import { workspaceHome } from "@/features/dashboard/constants/navigation";
import { api, type ApiUser } from "@/lib/api";
import "./login.css";

export function LoginView() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [visible, setVisible] = useState(false);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) return;
    setError("");
    setSubmitting(true);
    try {
      const { data: { user } } = await api<{ user: ApiUser }>("auth/login", {
        method: "POST", body: { email: email.trim(), password },
      });
      window.location.replace(workspaceHome(user));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Gagal masuk. Coba lagi.");
      setSubmitting(false);
    }
  }

  return <main className="ris-login-page">
    <div className="ris-login-shell">
      <section className="ris-login-story" aria-label="Tentang RIS Purchasing">
        <Link href="/login" className="ris-login-brand" aria-label="RIS Purchasing"><span className="ris-login-brandIcon"><Icon name="box" size={27}/></span><span>RIS<small>Purchasing</small></span></Link>
        <div className="ris-login-storyContent">
          <div className="ris-login-illustration" aria-hidden="true"><Icon name="box" size={110}/></div>
          <h1>Purchasing & Inventory</h1>
        </div>
      </section>

      <section className="ris-login-formSide" aria-labelledby="login-title">
        <div className="ris-login-formWrap">
          <h2 id="login-title">Masuk</h2>
          <form className="ris-login-form" onSubmit={submit}>
            <label htmlFor="login-email">Email</label>
            <input id="login-email" name="email" type="email" autoComplete="username" placeholder="nama@perusahaan.co.id" required maxLength={255} value={email} onChange={event => { setEmail(event.target.value); setError(""); }} aria-invalid={!!error} aria-describedby={error ? "login-error" : undefined}/>
            <label htmlFor="login-password">Kata sandi</label>
            <div className="ris-login-passwordField"><input id="login-password" name="password" type={visible ? "text" : "password"} autoComplete="current-password" placeholder="Masukkan kata sandi" required value={password} onChange={event => { setPassword(event.target.value); setError(""); }} aria-invalid={!!error} aria-describedby={error ? "login-error" : undefined}/><button type="button" onClick={() => setVisible(!visible)} aria-label={visible ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"} aria-pressed={visible} title={visible ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"}><Icon name="eye" size={19}/></button></div>
            {error && <p id="login-error" className="ris-login-error" role="alert">{error}</p>}
            <button type="submit" className="ris-login-submit" disabled={submitting}>{submitting ? "Membuka workspace..." : "Masuk"}<Icon name="arrow" size={18}/></button>
          </form>
        </div>
      </section>
    </div>
  </main>;
}
