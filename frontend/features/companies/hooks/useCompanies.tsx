"use client";

import { createContext, useContext, useEffect, useState } from "react";
import { api, apiList } from "@/lib/api";
import { useSession } from "@/features/auth/Session";
import type { Company } from "../types";

type CompaniesContextValue = {
  companies: Company[]; activeId: string; ready: boolean; busy: boolean; error: string; legacyCount: number;
  saveCompany: (company: Company) => Promise<boolean>;
  activateCompany: (id: string) => Promise<void>;
  deleteCompany: (id: string) => Promise<boolean>;
  importBrowserCompanies: () => Promise<void>;
  refresh: () => Promise<void>; clearError: () => void;
};
const CompaniesContext = createContext<CompaniesContextValue | null>(null);
function browserProfiles(): { companies: Company[]; activeId: string } {
  const raw = localStorage.getItem("stockly-companies-v1");
  if (!raw) return { companies: [], activeId: "" };
  const saved = JSON.parse(raw);
  if (!Array.isArray(saved.companies) || !saved.companies.every((company: Company) =>
    company && [company.id, company.name, company.address, company.phone, company.email, company.logo]
      .every(value => typeof value === "string") && company.name.trim())) {
    throw new Error("Profil lama tidak dapat dibaca. Data browser tetap disimpan.");
  }
  return saved;
}
function normalize(company: Company): Company { return { ...company, id: String(company.id) }; }
function nameKey(name: string) { return name.trim().replace(/\s+/g, " ").toLocaleLowerCase("id-ID"); }

export function CompaniesProvider({ children }: { children: React.ReactNode }) {
  const user = useSession();
  const canRead = user.permissions.includes("companies.view");
  const canManage = user.permissions.includes("companies.manage");
  const [companies, setCompanies] = useState<Company[]>([]);
  const [activeId, setActiveId] = useState("");
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [legacyCount, setLegacyCount] = useState(0);
  useEffect(() => {
    if (!canRead) return;
    const controller = new AbortController();
    apiList<Company>("companies", controller.signal).then(rows => {
      if (controller.signal.aborted) return;
      const loaded = rows.map(normalize);
      setCompanies(loaded);
      const preferred = String(user.default_company_id ?? "");
      setActiveId(loaded.some(company => company.id === preferred) ? preferred : loaded[0]?.id ?? "");
      setReady(true); setError("");
      if (canManage) {
        try { setLegacyCount(browserProfiles().companies.filter(old => !loaded.some(company => nameKey(company.name) === nameKey(old.name))).length); }
        catch (cause) { setError((cause as Error).message); }
      }
    }, cause => { if (!controller.signal.aborted) { setReady(false); setError(cause instanceof Error ? cause.message : "Profil PT gagal dimuat."); } });
    return () => controller.abort();
  }, [canRead, canManage, user.id, user.default_company_id]);
  async function refresh() {
    try {
      const rows = (await apiList<Company>("companies")).map(normalize);
      setCompanies(rows); setActiveId(current => rows.some(company => company.id === current) ? current : rows[0]?.id ?? "");
      setReady(true); setError("");
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Profil PT gagal dimuat."); }
  }
  async function saveCompany(company: Company) {
    if (!canManage || busy) return false;
    setBusy(true); setError("");
    try {
      const { id, ...body } = company;
      const existing = /^[1-9][0-9]*$/.test(id);
      const saved = normalize((await api<Company>(existing ? "companies/" + id : "companies", { method: existing ? "PATCH" : "POST", body })).data);
      setCompanies(current => current.some(entry => entry.id === saved.id)
        ? current.map(entry => entry.id === saved.id ? saved : entry) : [...current, saved]);
      setActiveId(current => current || saved.id);
      return true;
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Profil PT gagal disimpan."); return false; }
    finally { setBusy(false); }
  }
  async function activateCompany(id: string) {
    if (busy) return;
    setBusy(true); setError("");
    try { await api("companies/" + id + "/activate", { method: "POST" }); setActiveId(id); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "PT aktif gagal diubah."); }
    finally { setBusy(false); }
  }
  async function deleteCompany(id: string) {
    if (!canManage || busy) return false;
    setBusy(true); setError("");
    try {
      await api("companies/" + id, { method: "DELETE" });
      const next = companies.filter(company => company.id !== id);
      setCompanies(next); setActiveId(current => current === id ? next[0]?.id ?? "" : current);
      return true;
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Profil PT gagal dihapus."); return false; }
    finally { setBusy(false); }
  }
  async function importBrowserCompanies() {
    if (!canManage || busy) return;
    setBusy(true); setError("");
    try {
      const legacy = browserProfiles();
      const loaded = (await apiList<Company>("companies")).map(normalize);
      let preferred = "";
      for (const old of legacy.companies) {
        let saved = loaded.find(company => nameKey(company.name) === nameKey(old.name));
        if (!saved) {
          const { name, address, phone, email, logo } = old;
          saved = normalize((await api<Company>("companies", { method: "POST", body: { name, address, phone, email, logo } })).data);
          loaded.push(saved);
        }
        if (old.id === legacy.activeId) preferred = saved.id;
      }
      if (preferred) { await api("companies/" + preferred + "/activate", { method: "POST" }); setActiveId(preferred); }
      setCompanies(loaded); setLegacyCount(0);
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Impor belum selesai. Profil lama tetap disimpan; ulangi untuk melanjutkan."); }
    finally { setBusy(false); }
  }
  return <CompaniesContext value={{ companies, activeId, ready, busy, error, legacyCount, saveCompany, deleteCompany,
    clearError: () => setError(""), activateCompany, importBrowserCompanies, refresh }}>{children}</CompaniesContext>;
}
export function useCompanies() {
  const context = useContext(CompaniesContext);
  if (!context) throw new Error("useCompanies requires CompaniesProvider");
  return context;
}
