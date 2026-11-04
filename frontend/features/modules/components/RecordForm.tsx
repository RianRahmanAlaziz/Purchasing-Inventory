"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { modules } from "../constants";
import { moduleSchemas, type ModuleRecord } from "../utils/records";

export function RecordForm({ slug, title, initial, editing, error, ready, save, close }: {
  slug: string; title: string; initial: ModuleRecord; editing: boolean; error: string; ready: boolean;
  save: (record: ModuleRecord, editing: boolean) => boolean; close: () => void;
}) {
  const [record, setRecord] = useState(initial);
  const schema = moduleSchemas[slug];
  const columns = modules[slug].columns;
  function field(index: number, value: string) { setRecord(current => ({ ...current, cells: current.cells.map((cell, i) => i === index ? value : cell), ...(schema.fields[index]?.type === "date" ? { date: value } : {}) })); }
  return <Modal title={`${editing ? "Edit" : "Tambah"} ${title}`} onClose={close}>
    <form className="order-form company-form" onSubmit={event => { event.preventDefault(); if (save(record, editing)) close(); }}>
      {schema.fields.map((config, index) => <label key={columns[index]}>{columns[index]}{config.type === "amount" ? " (Rp)" : ""}<input autoFocus={index === 0} required maxLength={index === 0 ? 60 : 300} type={config.type === "amount" ? "number" : config.type === "email" ? "email" : config.type === "date" ? "date" : "text"} min={config.type === "amount" ? "0.01" : undefined} max={config.type === "amount" ? "1000000000000" : undefined} step={config.type === "amount" ? "0.01" : undefined} value={record.cells[index]} placeholder={config.placeholder} onChange={event => field(index, event.target.value)}/></label>)}
      {!schema.fields.some(field => field.type === "date") && <label>Tanggal pencatatan<input required type="date" value={record.date} onChange={event => setRecord({ ...record, date: event.target.value })}/></label>}
      <label>Status<select value={record.cells.at(-1)} onChange={event => field(schema.fields.length, event.target.value)}>{schema.statuses.map(status => <option key={status}>{status}</option>)}</select></label>
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="modal-actions"><Button type="button" variant="secondary" onClick={close}>Batal</Button><Button type="submit" disabled={!ready}>Simpan</Button></div>
    </form>
  </Modal>;
}
