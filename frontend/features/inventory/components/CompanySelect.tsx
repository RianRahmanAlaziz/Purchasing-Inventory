"use client";

import { useApiData } from "@/features/modules/hooks/useApiData";
import type { Row } from "@/features/modules/api-types";

export function CompanySelect({ value, onChange, required = false, label = "PT", disabled = false, compact = false }: {
  value: string; onChange: (value: string) => void; required?: boolean; label?: string; disabled?: boolean;
  /** Tanpa teks label yang terlihat, sejajar dengan filter lain di toolbar/header. */
  compact?: boolean;
}) {
  const companies = useApiData<Row[]>("inventory/companies", 0, true);
  return <label className={"inventory-company-filter" + (compact ? " compact" : "")}>{!compact && label}<select aria-label={label} required={required} disabled={disabled || companies.loading}
    value={value} onChange={event => onChange(event.target.value)}>
    <option value="">{required ? "Pilih PT" : "Semua PT"}</option>
    {companies.data?.map(company => <option key={company.id} value={company.id}>{String(company.name)}</option>)}
  </select>{companies.error && <small className="form-error" role="alert">{companies.error}</small>}</label>;
}
