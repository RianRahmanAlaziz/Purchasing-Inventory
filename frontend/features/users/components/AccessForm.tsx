"use client";

import { useState, type FormEvent, type ReactNode } from "react";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";

export function AccessForm({ title, children, disabled, close, save }: {
  title: string;
  children: ReactNode;
  disabled?: boolean;
  close: () => void;
  save: () => Promise<void>;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || disabled) return;
    setBusy(true);
    setError("");
    try {
      await save();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Perubahan gagal disimpan.");
      setBusy(false);
    }
  }

  return <Modal title={title} className="order-editor-modal api-record-editor access-editor" onClose={() => { if (!busy) close(); }}>
    <form className="order-form" onSubmit={event => void submit(event)}>
      <div className="po-form-body access-fields">{children}</div>
      <footer className="editor-footer">
        {error && <p className="form-error" role="alert">{error}</p>}
        <div className="modal-actions">
          <Button type="button" variant="secondary" disabled={busy} onClick={close}>Batal</Button>
          <Button type="submit" disabled={busy || disabled}>{busy ? "Menyimpan…" : "Simpan"}</Button>
        </div>
      </footer>
    </form>
  </Modal>;
}

type Choice = { name: string; display_name?: string | null };

export function AccessChoices({ label, options, selected, change, limit, searchable }: {
  label: string;
  options: Choice[];
  selected: string[];
  change: (next: string[]) => void;
  limit?: number;
  searchable?: boolean;
}) {
  const [query, setQuery] = useState("");
  const filter = query.trim().toLowerCase();
  const visible = options.filter(option => [option.name, option.display_name].some(value => value?.toLowerCase().includes(filter)));
  return <fieldset className="access-choices">
    <legend>{label}</legend>
    {searchable && <input aria-label={"Cari " + label.toLowerCase()} placeholder="Cari izin…" value={query} onChange={event => setQuery(event.target.value)}/>}
    <div className="access-choice-list">
      {visible.map(option => {
        const checked = selected.includes(option.name);
        return <label className="access-choice" key={option.name}>
          <input type="checkbox" checked={checked} disabled={!checked && limit !== undefined && selected.length >= limit}
            onChange={() => change(checked ? selected.filter(name => name !== option.name) : [...selected, option.name])}/>
          <span>{option.display_name && <strong>{option.display_name}</strong>}<span>{option.name}</span></span>
        </label>;
      })}
      {!visible.length && <p className="form-hint">Tidak ada pilihan yang cocok.</p>}
    </div>
    <p className="access-choice-count">{selected.length} dipilih{limit && " · Maksimal " + limit}</p>
  </fieldset>;
}
