"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { useCompanies } from "../hooks/useCompanies";
import type { Company } from "../types";

function CompanyForm({ initial, close }: { initial: Company; close: () => void }) {
  const { saveCompany, error } = useCompanies();
  const [company, setCompany] = useState(initial);
  const [logoError, setLogoError] = useState("");
  const [reading, setReading] = useState(false);
  function field(key: keyof Company, value: string) { setCompany(current => ({ ...current, [key]: value })); }
  async function upload(file?: File) {
    if (!file) return;
    setLogoError("");
    if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) { setLogoError("Gunakan logo PNG, JPG, atau WebP."); return; }
    if (file.size > 1024 * 1024) { setLogoError("Ukuran logo maksimal 1 MB."); return; }
    setReading(true);
    try {
      const result = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = reject; reader.readAsDataURL(file);
      });
      const image = new Image();
      await new Promise<void>((resolve, reject) => { image.onload = () => resolve(); image.onerror = reject; image.src = result; });
      field("logo", result);
    } catch { setLogoError("Logo tidak dapat dibaca. Pilih file gambar yang valid."); }
    finally { setReading(false); }
  }
  return <Modal title={initial.name ? "Edit Profil PT" : "Tambah PT"} onClose={close}><form className="order-form company-form" onSubmit={event => { event.preventDefault(); if (company.name.trim() && saveCompany({ ...company, name: company.name.trim() })) close(); }}>
    <div className="logo-upload"><div className="company-logo">{company.logo ? <img src={company.logo} alt={`Logo ${company.name || "PT"}`}/> : <Icon name="box" size={32}/>}</div><div><label>Logo PT<input type="file" accept="image/png,image/jpeg,image/webp" onChange={event => { void upload(event.target.files?.[0]); event.target.value = ""; }}/></label><p>PNG, JPG, atau WebP. Maksimal 1 MB.</p>{company.logo && <button type="button" className="table-link" onClick={() => field("logo", "")}>Hapus logo</button>}</div></div>
    {logoError && <p className="form-error" role="alert">{logoError}</p>}
    <label>Nama PT<input required maxLength={120} value={company.name} onChange={event => field("name", event.target.value)} placeholder="PT Nama Perusahaan"/></label>
    <label>Alamat<textarea rows={3} maxLength={400} value={company.address} onChange={event => field("address", event.target.value)} placeholder="Alamat lengkap perusahaan"/></label>
    <div className="form-row"><label>Telepon<input type="tel" maxLength={40} value={company.phone} onChange={event => field("phone", event.target.value)} placeholder="021-1234567"/></label><label>Email<input type="email" maxLength={120} value={company.email} onChange={event => field("email", event.target.value)} placeholder="purchasing@perusahaan.co.id"/></label></div>
    {error && <p className="form-error" role="alert">{error}</p>}
    <div className="modal-actions"><Button type="button" variant="secondary" onClick={close}>Batal</Button><Button disabled={reading} type="submit">{reading ? "Membaca logo..." : "Simpan Profil PT"}</Button></div>
  </form></Modal>;
}

export function CompaniesView() {
  const { companies, activeId, activateCompany, deleteCompany, clearError, ready, error } = useCompanies();
  const [editing, setEditing] = useState<Company | null>(null);
  const [deleting, setDeleting] = useState<Company | null>(null);
  return <><div className="page-heading"><div><div className="eyebrow">MASTER DATA / IDENTITAS PERUSAHAAN</div><h1>Profil Perusahaan</h1><p>Kelola nama, logo, dan identitas setiap PT untuk surat purchase order.</p></div><Button disabled={!ready} onClick={() => setEditing({ id: crypto.randomUUID(), name: "", address: "", phone: "", email: "", logo: "" })}><Icon name="plus" size={17}/> Tambah PT</Button></div>
    <div className="company-notice"><Icon name="file"/><p>PT aktif menjadi pilihan awal saat membuat PO. Identitas PT disalin ke setiap PO agar perubahan profil tidak mengubah surat yang sudah dibuat.</p></div>
    {error && <p className="form-error" role="alert">{error}</p>}
    <div className="companies-grid">{companies.map(company => <section className="panel company-card" key={company.id}><div className="company-card-top"><div className="company-logo">{company.logo ? <img src={company.logo} alt={`Logo ${company.name}`}/> : <Icon name="box" size={32}/>}</div>{activeId === company.id && <span className="company-active"><Icon name="check" size={14}/> PT aktif</span>}</div><h2>{company.name}</h2><p className="company-address">{company.address || "Alamat belum ditambahkan"}</p><div className="company-contacts"><span>{company.phone || "Telepon belum ditambahkan"}</span><span>{company.email || "Email belum ditambahkan"}</span></div><div className="company-card-actions"><button className="icon-button po-action-delete" title="Hapus PT" aria-label={`Hapus ${company.name}`} disabled={!ready} onClick={() => { clearError(); setDeleting(company); }}><Icon name="trash" size={17}/></button><Button variant="secondary" disabled={!ready} onClick={() => setEditing(company)}>Edit Profil</Button>{activeId !== company.id && <Button disabled={!ready} onClick={() => activateCompany(company.id)}>Jadikan PT Aktif</Button>}</div></section>)}</div>
    <p className="form-hint">Profil dan logo disimpan di browser ini. Sinkronisasi antar pengguna memerlukan integrasi backend.</p>
    {editing && <CompanyForm initial={editing} close={() => setEditing(null)}/>}
    {ready && companies.length === 0 && <section className="panel empty-state"><Icon name="box" size={32}/><h3>Belum ada perusahaan</h3><p>Tambahkan PT untuk membuat purchase order.</p></section>}
    {deleting && <Modal title="Hapus Perusahaan?" onClose={() => { clearError(); setDeleting(null); }}><p>Hapus <strong>{deleting.name}</strong>? Tindakan ini tidak dapat dibatalkan. Identitas PT pada PO yang sudah dibuat tetap tersimpan.</p>{deleting.id === activeId && <p className="form-hint">PT aktif akan diganti dengan PT lain jika tersedia.</p>}{error && <p className="form-error" role="alert">{error}</p>}<div className="modal-actions"><Button variant="secondary" autoFocus onClick={() => { clearError(); setDeleting(null); }}>Batal</Button><Button className="danger" disabled={!ready} onClick={() => { if (deleteCompany(deleting.id)) setDeleting(null); }}>Ya, Hapus PT</Button></div></Modal>}
  </>;
}
