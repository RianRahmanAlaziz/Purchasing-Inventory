"use client";

import { useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Icon } from "@/components/ui/Icon";
import "./login.css";

export function LoginView() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [visible, setVisible] = useState(false);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) return;
    setError("");
    if (email.trim().toLowerCase() !== "admin@ris.example" || password !== "RISdemo2026!") {
      setError("Email atau kata sandi salah. Coba akun demo.");
      return;
    }
    setSubmitting(true);
    router.replace("/dashboard");
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
            <input id="login-email" name="email" type="email" autoComplete="username" placeholder="nama@perusahaan.co.id" required maxLength={120} value={email} onChange={event => { setEmail(event.target.value); setError(""); }} aria-invalid={!!error} aria-describedby={error ? "login-error" : undefined}/>
            <label htmlFor="login-password">Kata sandi</label>
            <div className="ris-login-passwordField"><input id="login-password" name="password" type={visible ? "text" : "password"} autoComplete="current-password" placeholder="Masukkan kata sandi" required value={password} onChange={event => { setPassword(event.target.value); setError(""); }} aria-invalid={!!error} aria-describedby={error ? "login-error" : undefined}/><button type="button" onClick={() => setVisible(!visible)} aria-label={visible ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"} aria-pressed={visible} title={visible ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"}><Icon name="eye" size={19}/></button></div>
            {error && <p id="login-error" className="ris-login-error" role="alert">{error}</p>}
            <button type="submit" className="ris-login-submit" disabled={submitting}>{submitting ? "Membuka workspace..." : "Masuk"}<Icon name="arrow" size={18}/></button>
          </form>
          <details className="ris-login-demo"><summary>Akun demo</summary><dl><div><dt>Email</dt><dd>admin@ris.example</dd></div><div><dt>Kata sandi</dt><dd>RISdemo2026!</dd></div></dl><button type="button" onClick={() => { setEmail("admin@ris.example"); setPassword("RISdemo2026!"); setError(""); }}>Gunakan akun demo <Icon name="arrow" size={14}/></button><small>Demo tanpa autentikasi server.</small></details>
        </div>
      </section>
    </div>
  </main>;
}
