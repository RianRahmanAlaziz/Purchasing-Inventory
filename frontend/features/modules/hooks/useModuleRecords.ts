"use client";

import { useEffect, useState } from "react";
import { deleteModuleRecord, initialModuleRecords, readModuleRecords, saveModuleRecord, type ModuleRecord } from "../utils/records";

export function useModuleRecords(slug: string) {
  const storageKey = `stockly-module-${slug}-v1`;
  const [records, setRecords] = useState(() => initialModuleRecords(slug));
  const [ready, setReady] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    function load() {
      try {
        const raw = localStorage.getItem(storageKey);
        setRecords(raw === null ? initialModuleRecords(slug) : readModuleRecords(slug, raw));
        setReady(true); setError("");
      } catch { setReady(false); setError("Data tidak dapat dibaca. Periksa penyimpanan browser lalu muat ulang halaman."); }
    }
    function onStorage(event: StorageEvent) { if (event.key === storageKey || event.key === null) load(); }
    const timer = window.setTimeout(load, 0);
    window.addEventListener("storage", onStorage);
    return () => { window.clearTimeout(timer); window.removeEventListener("storage", onStorage); };
  }, [slug, storageKey]);

  function mutate(change: (current: ModuleRecord[]) => ModuleRecord[]) {
    if (!ready) return false;
    try {
      const raw = localStorage.getItem(storageKey);
      const next = change(raw === null ? records : readModuleRecords(slug, raw));
      localStorage.setItem(storageKey, JSON.stringify(next));
      setRecords(next); setError(""); return true;
    } catch (cause) {
      setError(cause instanceof Error && !(cause instanceof DOMException) ? cause.message : "Perubahan belum tersimpan. Penyimpanan browser penuh atau tidak tersedia.");
      return false;
    }
  }
  return { records, ready, error, clearError: () => setError(""), save: (record: ModuleRecord, editing: boolean) => mutate(current => saveModuleRecord(slug, current, record, editing)), remove: (id: string) => mutate(current => deleteModuleRecord(current, id)) };
}
