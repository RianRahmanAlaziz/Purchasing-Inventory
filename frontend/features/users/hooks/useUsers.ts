"use client";

import { useEffect, useState } from "react";
import { modules } from "@/features/modules/constants";
import { demoRecordDates } from "@/features/modules/constants/recordDates";
import { deleteUser, readUsers, saveUser, type User } from "../utils/users";

const storageKey = "stockly-users-v1";
const initialUsers = readUsers(JSON.stringify(modules.users.rows.map(([id, name, email, role, status]) => ({ id, name, email, role, status, createdAt: demoRecordDates[id] }))));

export function useUsers() {
  const [users, setUsers] = useState<User[]>(initialUsers);
  const [ready, setReady] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    function load() {
      try {
        const raw = localStorage.getItem(storageKey);
        setUsers(raw === null ? initialUsers : readUsers(raw));
        setReady(true); setError("");
      } catch {
        setReady(false);
        setError("Data pengguna tidak dapat dibaca. Periksa penyimpanan browser lalu muat ulang halaman.");
      }
    }
    function onStorage(event: StorageEvent) {
      if (event.key === storageKey || event.key === null) load();
    }
    const timer = window.setTimeout(load, 0);
    window.addEventListener("storage", onStorage);
    return () => { window.clearTimeout(timer); window.removeEventListener("storage", onStorage); };
  }, []);

  function mutate(change: (current: User[]) => User[]) {
    if (!ready) return false;
    try {
      const raw = localStorage.getItem(storageKey);
      const next = change(raw === null ? users : readUsers(raw));
      localStorage.setItem(storageKey, JSON.stringify(next));
      setUsers(next); setError(""); return true;
    } catch (cause) {
      setError(cause instanceof Error && !(cause instanceof DOMException) ? cause.message : "Perubahan belum tersimpan. Penyimpanan browser penuh atau tidak tersedia.");
      return false;
    }
  }
  return {
    users, ready, error, clearError: () => setError(""),
    save: (user: User, editing: boolean) => mutate(current => saveUser(current, user, editing)),
    remove: (id: string) => mutate(current => deleteUser(current, id)),
  };
}
