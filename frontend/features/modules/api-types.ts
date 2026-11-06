import type { ApiUser } from "@/lib/api";
import { workspaceToday } from "@/lib/export-period";

export type Row = Record<string, unknown> & { id: number };
export type Option = { value: string; label: string };
export type FormValue = string | string[] | boolean | File | null;
export type FormValues = Record<string, FormValue>;

export type OptionSource = {
  path: string | ((values: FormValues, row?: Row) => string | null);
  options: (rows: Row[]) => Option[];
  single?: boolean;
};

export type Field = {
  key: string;
  label: string;
  type?: "text" | "email" | "textarea" | "date" | "quantity" | "money" | "id" | "boolean" | "multiple" | "file";
  accept?: string;
  choices?: Option[];
  from?: string | ((row: Row) => FormValue);
  source?: OptionSource;
  required?: boolean;
  min?: number;
  maxLength?: number;
  initial?: string | (() => string);
  createOnly?: boolean;
  omitEmpty?: boolean;
  resetLines?: boolean;
};

export type Column = {
  label: string;
  key: string;
  format?: "date" | "datetime" | "money" | "quantity" | "status";
};

export type Operation = {
  label: string;
  permission?: string;
  suffix?: string;
  method?: "POST" | "DELETE";
  reason?: boolean;
  adjustment?: boolean;
};

export type ApiModule = {
  endpoint: string;
  title: string;
  description: string;
  columns: Column[];
  date: string;
  status?: string;
  details?: Column[];
  loadDetail?: boolean;
  groups?: { key: string; title: string; columns: Column[] }[];
  fields?: Field[];
  lines?: { fields: Field[]; limit: number };
  mutations?: {
    create?: string | ((user: ApiUser) => boolean);
    edit?: string;
    remove?: string;
  };
  edit?: (row: Row) => boolean;
  remove?: (row: Row) => boolean;
  operations?: (row: Row, user: ApiUser) => Operation[];
  filter?: (row: Row) => boolean;
  print?: boolean;
};

export function get(row: Row | undefined, key: string): unknown {
  return key.split(".").reduce<unknown>((value, part) =>
    value && typeof value === "object" ? (value as Record<string, unknown>)[part] : undefined, row);
}

export function records(row: Row | undefined, key: string): Row[] {
  const value = get(row, key);
  return Array.isArray(value) ? value : [];
}

export function datePart(value: unknown) {
  if (typeof value !== "string") return "";
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
  const date = new Date(value);
  return Number.isNaN(date.valueOf()) ? "" : workspaceToday(date);
}
