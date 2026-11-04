import type { Company } from "../types/index.ts";

export function removeCompany(companies: Company[], activeId: string, id: string) {
  if (!companies.some(company => company.id === id)) throw new Error("PT tidak ditemukan.");
  const remaining = companies.filter(company => company.id !== id);
  return { companies: remaining, activeId: activeId === id ? remaining[0]?.id || "" : activeId };
}
