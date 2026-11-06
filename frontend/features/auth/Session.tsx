"use client";

import { createContext, useContext } from "react";
import type { ApiUser } from "@/lib/api";

export const Session = createContext<ApiUser | null>(null);

export function useSession() {
  const user = useContext(Session);
  if (!user) throw new Error("Sesi belum dimuat.");
  return user;
}
