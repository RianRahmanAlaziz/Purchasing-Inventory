"use client";

import { createContext, useContext, useEffect, useState } from "react";
import type { Company } from "../types";
import { removeCompany } from "../utils/mutations";

const defaultCompany: Company = { id: "company-nusantara", name: "PT Nusantara Group", address: "", phone: "", email: "", logo: "" };
const storageKey = "stockly-companies-v1";

type CompaniesContextValue = {
  companies: Company[];
  activeId: string;
  ready: boolean;
  error: string;
  saveCompany: (company: Company) => boolean;
  activateCompany: (id: string) => void;
  deleteCompany: (id: string) => boolean;
  clearError: () => void;
};
const CompaniesContext = createContext<CompaniesContextValue | null>(null);

function validCompany(value: unknown): value is Company {
  if (!value || typeof value !== "object") return false;
  const company = value as Company;
  return [company.id, company.name, company.address, company.phone, company.email, company.logo].every(item => typeof item === "string") && company.name.trim().length > 0 && (!company.logo || /^data:image\/(png|jpeg|webp);base64,/.test(company.logo));
}

export function CompaniesProvider({ children }: { children: React.ReactNode }) {
  const [companies, setCompanies] = useState<Company[]>([defaultCompany]);
  const [activeId, setActiveId] = useState(defaultCompany.id);
  const [ready, setReady] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    function load() {
      try {
        const raw = localStorage.getItem(storageKey);
        if (raw !== null) {
          const saved = JSON.parse(raw);
          if (!Array.isArray(saved.companies) || !saved.companies.every(validCompany) || new Set(saved.companies.map((company: Company) => company.id)).size !== saved.companies.length) throw new Error("Data tidak valid");
          setCompanies(saved.companies);
          setActiveId(saved.companies.some((company: Company) => company.id === saved.activeId) ? saved.activeId : saved.companies[0]?.id || "");
        } else {
          setCompanies([defaultCompany]); setActiveId(defaultCompany.id);
        }
        setReady(true); setError("");
      } catch { setReady(false); setError("Penyimpanan profil PT tidak dapat dibaca. Periksa penyimpanan browser lalu muat ulang."); }
    }
    function onStorage(event: StorageEvent) { if (event.key === storageKey || event.key === null) load(); }
    const timer = window.setTimeout(load, 0);
    window.addEventListener("storage", onStorage);
    return () => { window.clearTimeout(timer); window.removeEventListener("storage", onStorage); };
  }, []);

  function persist(nextCompanies: Company[], nextActiveId: string) {
    if (!ready) return false;
    try {
      localStorage.setItem(storageKey, JSON.stringify({ companies: nextCompanies, activeId: nextActiveId }));
      setCompanies(nextCompanies); setActiveId(nextActiveId); setError(""); return true;
    } catch { setError("Profil belum tersimpan. Penyimpanan browser penuh atau tidak tersedia. Coba logo berukuran lebih kecil."); return false; }
  }

  function saveCompany(company: Company) {
    if (!validCompany(company)) return false;
    const exists = companies.some(current => current.id === company.id);
    return persist(exists ? companies.map(current => current.id === company.id ? company : current) : [...companies, company], activeId || company.id);
  }

  function deleteCompany(id: string) {
    try { const next = removeCompany(companies, activeId, id); return persist(next.companies, next.activeId); }
    catch (cause) { setError((cause as Error).message); return false; }
  }

  return <CompaniesContext value={{ companies, activeId, ready, error, saveCompany, deleteCompany, clearError: () => setError(""), activateCompany: id => { if (companies.some(company => company.id === id)) persist(companies, id); } }}>{children}</CompaniesContext>;
}

export function useCompanies() {
  const context = useContext(CompaniesContext);
  if (!context) throw new Error("useCompanies requires CompaniesProvider");
  return context;
}
