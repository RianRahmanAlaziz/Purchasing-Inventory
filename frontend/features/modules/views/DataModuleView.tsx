"use client";

import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ExportButton } from "@/components/ui/ExportButton";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import { navigation } from "@/features/dashboard/constants/navigation";
import { rupiah } from "@/lib/utils";
import { workspaceToday } from "@/lib/export-period";
import { modules } from "../constants";
import { useModuleRecords } from "../hooks/useModuleRecords";
import { RecordForm } from "../components/RecordForm";
import { moduleSchemas, type ModuleRecord } from "../utils/records";

export function DataModuleView({ slug }: { slug: string }) {
  const params = useSearchParams();
  const { records, ready, error, clearError, save, remove } = useModuleRecords(slug);
  const [query, setQuery] = useState(params.get("q") || "");
  const [status, setStatus] = useState(params.get("status") || "Semua status");
  const [selection, setSelection] = useState<{ mode: "create" | "view" | "edit" | "delete"; record: ModuleRecord } | null>(null);
  const [notice, setNotice] = useState("");
  const config = modules[slug];
  const schema = moduleSchemas[slug];
  const title = navigation.flatMap(group => group.items).find(item => item.slug === slug)?.name || slug;
  const filtered = records.filter(record => record.cells.join(" ").toLowerCase().includes(query.toLowerCase()) && (status === "Semua status" || record.cells.at(-1) === status));
  const pagination = usePagination(filtered, JSON.stringify([slug, query, status]));
  const hasDate = schema.fields.some(field => field.type === "date");
  const columns = hasDate ? config.columns : [...config.columns, "Tanggal pencatatan"];
  const values = (record: ModuleRecord) => hasDate ? record.cells : [...record.cells, record.date];
  function display(value: string, index: number) { return schema.fields[index]?.type === "amount" ? rupiah(Number(value)) : value; }
  function open(mode: NonNullable<typeof selection>["mode"], record: ModuleRecord) { clearError(); setNotice(""); setSelection({ mode, record }); }
  function close() { clearError(); setSelection(null); }
  function create() {
    const date = workspaceToday();
    const cells = [...schema.fields.map(field => field.type === "date" ? date : ""), schema.statuses[0]];
    open("create", { id: crypto.randomUUID(), cells, date });
  }
  return <>
    <div className="page-heading"><div><div className="eyebrow"><span className="status-dot"/> WORKSPACE / MANAGEMENT</div><h1>{title}</h1><p>{config.description}</p></div><div className="heading-actions"><ExportButton disabled={!ready} filename={`${slug}.csv`} columns={columns} records={filtered.map(record => ({ date: record.date, cells: values(record) }))}/><Button disabled={!ready} onClick={create}><Icon name="plus" size={17}/> Tambah {title}</Button></div></div>
    <p className="form-hint">Modul demo. Data hanya tersimpan di browser ini.</p>
    {notice && <p className="form-hint" role="status">{notice}</p>}{error && !selection && <p className="form-error" role="alert">{error}</p>}
    <section className="panel module-panel"><div className="panel-header"><div><h2>Daftar {title} <span className="count-pill">{filtered.length}</span></h2></div></div>
      <div className="table-toolbar"><label className="table-search"><Icon name="search" size={18}/><input aria-label={`Cari ${title}`} placeholder={`Cari ${title.toLowerCase()}...`} value={query} onChange={event => setQuery(event.target.value)}/></label><select aria-label="Filter status" value={status} onChange={event => setStatus(event.target.value)}><option>Semua status</option>{schema.statuses.map(value => <option key={value}>{value}</option>)}</select></div>
      {!ready && !error ? <p className="loading-state">Memuat data...</p> : <><div className="table-scroll"><table><thead><tr>{columns.map(column => <th key={column}>{column}</th>)}<th>Aksi</th></tr></thead><tbody>{pagination.items.map(record => <tr key={record.id}>{values(record).map((value, index) => <td key={columns[index]}>{index === 0 ? <button className="table-link" onClick={() => open("view", record)}>{value}</button> : index === config.columns.length - 1 ? <Badge tone={/Rejected|Nonaktif/.test(value) ? "gray" : /Pending|Unpaid|Requested|Preparing/.test(value) ? "amber" : /Sent|Uploaded|Keluar/.test(value) ? "blue" : "green"}>{value}</Badge> : display(value, index)}</td>)}<td><div className="po-row-actions"><button className="icon-button po-action-view" title="Lihat detail" aria-label={`Lihat ${record.cells[0]}`} onClick={() => open("view", record)}><Icon name="eye" size={17}/></button><button className="icon-button po-action-edit" title="Edit" aria-label={`Edit ${record.cells[0]}`} disabled={!ready} onClick={() => open("edit", record)}><Icon name="edit" size={17}/></button><button className="icon-button po-action-delete" title="Hapus" aria-label={`Hapus ${record.cells[0]}`} disabled={!ready} onClick={() => open("delete", record)}><Icon name="trash" size={17}/></button></div></td></tr>)}</tbody></table>{!filtered.length && <div className="empty-state"><Icon name="search" size={32}/><h3>Data tidak ditemukan</h3><p>Tambahkan data atau ubah pencarian dan filter.</p></div>}</div><Pagination {...pagination} label={title}/></>}
    </section>
    {selection && (selection.mode === "create" || selection.mode === "edit") && <RecordForm key={selection.record.id} slug={slug} title={title} initial={selection.record} editing={selection.mode === "edit"} ready={ready} error={error} save={(record, editing) => { const saved = save(record, editing); if (saved) setNotice("Data berhasil disimpan."); return saved; }} close={close}/>}
    {selection?.mode === "view" && <Modal title={`Detail ${selection.record.cells[0]}`} onClose={close}><div className="detail-grid">{values(selection.record).map((value, index) => <p key={columns[index]}>{columns[index]}<strong>{display(value, index)}</strong></p>)}</div><div className="modal-actions"><Button variant="secondary" onClick={close}>Tutup</Button><Button disabled={!ready} onClick={() => open("edit", selection.record)}>Edit</Button></div></Modal>}
    {selection?.mode === "delete" && <Modal title="Hapus Data?" onClose={close}><div className="po-delete-notice"><span><Icon name="trash" size={25}/></span><p>Hapus <strong>{selection.record.cells[0]}</strong> dari {title}? Tindakan ini tidak dapat dibatalkan.</p></div>{error && <p className="form-error" role="alert">{error}</p>}<div className="modal-actions"><Button variant="secondary" autoFocus onClick={close}>Batal</Button><Button className="danger" disabled={!ready} onClick={() => { if (remove(selection.record.id)) { setNotice("Data berhasil dihapus."); close(); } }}>Ya, Hapus</Button></div></Modal>}
  </>;
}
