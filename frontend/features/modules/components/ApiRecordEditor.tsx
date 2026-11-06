"use client";

import { useEffect, useId, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, apiList } from "@/lib/api";
import { quantityInput } from "@/lib/quantity";
import { datePart, get, records, type ApiModule, type Field, type FormValue, type FormValues, type Row } from "../api-types";

function initialValues(fields: Field[], row?: Row): FormValues {
  return Object.fromEntries(fields.map((field): [string, FormValue] => {
    if (field.type === "file") return [field.key, null];
    const value = row && (typeof field.from === "function" ? field.from(row) : get(row, field.from ?? field.key));
    if (field.type === "boolean") return [field.key, value !== false];
    if (field.type === "multiple") return [field.key, Array.isArray(value) ? value.map(String) : []];
    const initial = typeof field.initial === "function" ? field.initial() : field.initial;
    return [field.key, value != null ? (field.type === "date" ? datePart(value) : field.type === "quantity" ? quantityInput(value) : String(value)) : initial ?? ""];
  }));
}

function payload(fields: Field[], values: FormValues) {
  const body: Record<string, unknown> = {};
  for (const field of fields) {
    const value = values[field.key];
    if (value instanceof File) { body[field.key] = value; continue; }
    if (Array.isArray(value)) body[field.key] = value.map(Number);
    else if (typeof value === "boolean") body[field.key] = value;
    else {
      const text = value?.trim() ?? "";
      if (text) body[field.key] = field.type === "id" ? Number(text) : text;
      else if (!field.omitEmpty) body[field.key] = null;
    }
  }
  return body;
}

function sourcePath(field: Field, values: FormValues, row?: Row) {
  const path = field.source?.path;
  return typeof path === "function" ? path(values, row) : path;
}

type Lookup = { rows: Row[]; error?: string };

function useLookups(fields: Field[], values: FormValues, row?: Row) {
  const sources: Record<string, boolean> = {};
  for (const field of fields) {
    const path = sourcePath(field, values, row);
    if (path) sources[path] = !!field.source?.single;
  }
  const key = JSON.stringify(sources);
  const [result, setResult] = useState<Record<string, Lookup>>({});

  useEffect(() => {
    const controller = new AbortController();
    const paths: [string, boolean][] = Object.entries(JSON.parse(key));
    paths.forEach(async ([path, single]) => {
      let entry: Lookup;
      try {
        const rows = single
          ? [(await api<Row>(path, { signal: controller.signal })).data]
          : await apiList<Row>(path, controller.signal);
        entry = { rows };
      } catch (cause) {
        entry = { rows: [], error: cause instanceof Error ? cause.message : "Pilihan gagal dimuat." };
      }
      if (!controller.signal.aborted) setResult(current => ({ ...current, [path]: entry }));
    });
    return () => controller.abort();
  }, [key]);

  return result;
}

function Input({ field, value, lookup, path, change }: {
  field: Field; value: FormValue; lookup?: Lookup; path?: string | null; change: (value: FormValue) => void;
}) {
  const { label, required, type, maxLength } = field;
  const id = useId();
  const caption = <span className="editor-label">{label}{required && <span aria-hidden="true"> *</span>}</span>;
  const options = field.choices ?? (lookup && field.source ? field.source.options(lookup.rows) : []);

  if (type === "file") return <label className="editor-field editor-field-notes">{caption}
    <input type="file" required={required} accept={field.accept} onChange={event => change(event.target.files?.[0] ?? null)}/>
    <small className="form-hint">PDF, PNG, JPG, atau WebP. Maksimal 5 MB. Kosongkan untuk mempertahankan berkas yang sudah ada.</small>
  </label>;

  if (type === "boolean") return <label className="editor-field">{caption}<select value={String(value)} onChange={event => change(event.target.value === "true")}>
    <option value="true">Ya</option><option value="false">Tidak</option>
  </select></label>;

  if (type === "multiple") {
    const selected = Array.isArray(value) ? value : [];
    const available = options.filter(option => !selected.includes(option.value));
    const chosen = selected.map(value => {
      const option = options.find(option => option.value === value);
      return { value, label: option?.label ?? "Tarif #" + value, unavailable: !!lookup && !lookup.error && !option };
    });
    const unavailable = chosen.some(option => option.unavailable);
    const placeholder = !path ? "Pilih tanggal pemesanan"
      : !lookup ? "Memuat tarif pajak…"
      : lookup.error ? "Tarif pajak gagal dimuat"
      : !options.length ? "Belum ada tarif aktif pada tanggal ini"
      : !available.length ? "Semua tarif sudah dipilih"
      : "Pilih tarif pajak";

    return <div className="editor-field">
      <label htmlFor={id}>{caption}<span className="editor-optional">Opsional</span></label>
      <select id={id} value="" disabled={!available.length} aria-describedby={unavailable ? id + "-warning" : undefined}
        onChange={event => { if (event.target.value) change([...selected, event.target.value]); }}>
        <option value="" disabled>{placeholder}</option>
        {available.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
      </select>
      {chosen.length > 0 && <ul className="editor-tax-list" aria-label="Tarif pajak terpilih">
        {chosen.map(option => <li key={option.value} className={option.unavailable ? "editor-tax-unavailable" : undefined}>
          <span>{option.label}</span>
          <button type="button" aria-label={"Hapus " + option.label} onClick={() => change(selected.filter(value => value !== option.value))}>
            <Icon name="close" size={13}/>
          </button>
        </li>)}
      </ul>}
      {unavailable && <small id={id + "-warning"} className="editor-tax-warning">Hapus tarif yang sudah tidak berlaku untuk tanggal pemesanan ini.</small>}
    </div>;
  }

  const text = String(value);
  if (field.choices || (field.source && !lookup?.error)) {
    return <label className="editor-field">{caption}<select value={text} required={required} disabled={!field.choices && (!path || !lookup)}
      onChange={event => change(event.target.value)}>
      <option value="">{field.choices ? "Pilih " + label.toLowerCase() : !path ? "Isi pilihan terkait terlebih dahulu" : !lookup ? "Memuat pilihan..." : options.length ? "Pilih " + label.toLowerCase() : "Tidak ada pilihan tersedia"}</option>
      {text && !options.some(option => option.value === text) && <option value={text}>{field.choices ? text : "ID " + text + " (pilihan tersimpan)"}</option>}
      {options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
    </select></label>;
  }

  const decimal = type === "quantity" || type === "money";
  const pattern = type === "id" ? "[1-9][0-9]*" : decimal
    ? (field.min ? "(?!0(?:[.]0+)?$)" : "") + "(?:0|[1-9][0-9]*)(?:[.][0-9]{1," + (type === "money" ? 2 : 3) + "})?"
    : undefined;

  return <label className={type === "textarea" ? "editor-field editor-field-notes" : "editor-field"}>{caption}{type === "textarea"
    ? <textarea rows={2} required={required} maxLength={maxLength} value={text} onChange={event => change(event.target.value)}/>
    : <input type={type === "date" || type === "email" ? type : "text"} value={text}
      required={required} maxLength={maxLength} pattern={pattern}
      inputMode={decimal ? "decimal" : type === "id" ? "numeric" : undefined}
      onChange={event => change(event.target.value)}/>}
    {lookup?.error && <small className="form-hint">Pilihan tidak dapat dimuat. Masukkan ID jika diketahui.</small>}
  </label>;
}

type Line = { key: string; values: FormValues };
type Props = { config: ApiModule; row?: Row; close: () => void; saved: () => void };

export function ApiRecordEditor({ config, row, close, saved }: Props) {
  const fields = (config.fields ?? []).filter(field => !row || !field.createOnly);
  const lineFields = config.lines?.fields ?? [];
  const [values, setValues] = useState(() => initialValues(fields, row));
  const [lines, setLines] = useState<Line[]>(() => {
    const existing = records(row, "items");
    if (existing.length) return existing.map(line => ({ key: String(line.id), values: initialValues(lineFields, line) }));
    return config.lines ? [{ key: "first", values: initialValues(lineFields) }] : [];
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const lookups = useLookups([...fields, ...lineFields], values, row);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    try {
      const body = payload(fields, values);
      if (config.lines) body.items = lines.map(line => payload(lineFields, line.values));
      const multipart = Object.values(body).some(value => value instanceof File);
      const form = new FormData();
      if (multipart) {
        for (const [key, value] of Object.entries(body)) form.set(key, value instanceof File ? value : value == null ? "" : String(value));
        if (row) form.set("_method", "PATCH");
      }
      await api(row ? config.endpoint + "/" + row.id : config.endpoint, { method: row && !multipart ? "PATCH" : "POST", body: multipart ? form : body });
      saved();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Perubahan gagal disimpan.");
      setBusy(false);
    }
  }

  function render(field: Field, value: FormValue, change: (value: FormValue) => void) {
    const path = sourcePath(field, values, row);
    return <Input key={field.key} field={field} value={value} path={path} lookup={path ? lookups[path] : undefined} change={change}/>;
  }

  return <Modal title={(row ? "Edit " : "Tambah ") + config.title}
    description={config.endpoint === "purchase-orders" ? "Lengkapi informasi pemesanan dan rincian barang." : undefined}
    className="order-editor-modal api-record-editor" onClose={() => { if (!busy) close(); }}>
    <form className="order-form" onSubmit={event => void submit(event)}>
      <div className="po-form-body">
        <div className="form-row editor-fields">{fields.map(field => render(field, values[field.key], value => {
          setValues(current => ({ ...current, [field.key]: value }));
          if (field.resetLines) setLines([{ key: "first", values: initialValues(lineFields) }]);
        }))}</div>
        {config.lines && <>
          <div className="line-editor-heading"><h3>Rincian barang</h3><Button type="button" variant="secondary" disabled={busy || lines.length >= config.lines.limit}
            onClick={() => setLines(current => [...current, { key: crypto.randomUUID(), values: initialValues(lineFields) }])}><Icon name="plus" size={16}/> Tambah barang</Button></div>
          <div className="line-editors">{lines.map((line, index) => <fieldset className="line-editor" key={line.key}>
            <legend>Barang {index + 1}</legend>
            <div className="form-row">{lineFields.map(field => render(field, line.values[field.key], value => {
              setLines(current => current.map(entry => entry.key === line.key
                ? { ...entry, values: { ...entry.values, [field.key]: value } } : entry));
            }))}</div>
            {lines.length > 1 && <button type="button" className="remove-line" disabled={busy} onClick={() => setLines(current => current.filter(entry => entry.key !== line.key))}>Hapus barang {index + 1}</button>}
          </fieldset>)}</div>
        </>}
      </div>
      <footer className="editor-footer">
        {error && <p className="form-error" role="alert">{error}</p>}
        <div className="modal-actions">
          <Button type="button" variant="secondary" disabled={busy} onClick={close}>Batal</Button>
          <Button type="submit" disabled={busy}>{busy ? "Menyimpan..." : "Simpan"}</Button>
        </div>
      </footer>
    </form>
  </Modal>;
}
