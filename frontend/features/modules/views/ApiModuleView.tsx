"use client";

import { useState, type FormEvent } from "react";
import { useSearchParams } from "next/navigation";
import { useSession } from "@/features/auth/Session";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ExportButton } from "@/components/ui/ExportButton";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import { api } from "@/lib/api";
import { formatQuantity, quantityInput } from "@/lib/quantity";
import { documentMoney } from "@/features/purchase-orders/utils/totals";
import { ApiRecordEditor } from "../components/ApiRecordEditor";
import { ApiPurchaseOrderPreview } from "@/features/purchase-orders/components/ApiPurchaseOrderPreview";
import { CreateOrderModal } from "@/features/purchase-orders/components/CreateOrderModal";
import { apiModules } from "../constants/apiModules";
import { useApiData } from "../hooks/useApiData";
import { datePart, get, records, type ApiModule, type Column, type Operation, type Row } from "../api-types";

const statuses: Record<string, string> = {
  true: "Aktif", false: "Nonaktif",
  draft: "Draft", pending_approval: "Menunggu persetujuan", approved: "Disetujui", sent: "Dikirim",
  received_by_supplier: "Diterima supplier", rejected: "Ditolak", cancelled: "Dibatalkan",
  pending: "Menunggu", confirmed: "Dikonfirmasi", partially_received: "Diterima sebagian", completed: "Selesai",
  fully_received: "Diterima penuh", discrepancy: "Ada selisih",
  requested: "Diminta", preparing: "Disiapkan", received_by_employee: "Diterima karyawan",
  adjustment: "Penyesuaian", grn_in: "Stok masuk", distribution_out: "Stok keluar",
  unpaid: "Belum dibayar", partially_paid: "Dibayar sebagian", paid: "Lunas", overdue: "Jatuh tempo",
  pending_verification: "Menunggu verifikasi", verified: "Terverifikasi", not_uploaded: "Belum diunggah", uploaded: "Diunggah", received: "Diterima",
  addition: "Tambahan", withholding: "Potongan",
};

function statusText(value: unknown, field: string) {
  if (value == null) return "—";
  if (field === "is_low_stock") return value ? "Stok menipis" : "Tersedia";
  return statuses[String(value)] ?? String(value).replaceAll("_", " ");
}

function text(row: Row, column: Column) {
  const value = get(row, column.key);
  if (value == null || value === "") return "—";
  switch (column.format) {
    case "date": return datePart(value).split("-").reverse().join("/");
    case "datetime": {
      const date = new Date(String(value));
      return Number.isNaN(date.valueOf()) ? String(value) : date.toLocaleString("id-ID", { timeZone: "Asia/Bangkok" });
    }
    case "money": return documentMoney(String(value));
    case "quantity": return formatQuantity(value);
    case "status": return statusText(value, column.key);
    default: return String(value);
  }
}

function Cell({ row, column }: { row: Row; column: Column }) {
  const value = get(row, column.key);
  if (column.format !== "status") return <>{text(row, column)}</>;
  const tone = column.key === "is_low_stock" ? (value ? "amber" : "green")
    : ["rejected", "cancelled", "discrepancy", false].includes(value as string | boolean) ? "red"
    : ["pending", "pending_approval", "pending_verification", "requested", "preparing", "overdue", "unpaid"].includes(String(value)) ? "amber"
    : ["approved", "completed", "fully_received", "received_by_employee", "verified", "paid", true].includes(value as string | boolean) ? "green" : "blue";
  return <Badge tone={tone}>{text(row, column)}</Badge>;
}

function Details({ row: summary, config, close, edit, act, preview }: {
  row: Row; config: ApiModule; close: () => void; edit: (row: Row) => void;
  act: (operation: Operation, row: Row) => void; preview: (row: Row) => void;
}) {
  const user = useSession();
  const detail = useApiData<Row>(config.loadDetail ? config.endpoint + "/" + summary.id : null);
  const row = detail.data ?? summary;
  const operations = (config.operations?.(row, user) ?? [])
    .filter(operation => !operation.permission || user.permissions.includes(operation.permission));
  const canEdit = config.mutations?.edit && user.permissions.includes(config.mutations.edit) && config.edit?.(row);
  const canRemove = config.mutations?.remove && user.permissions.includes(config.mutations.remove) && config.remove?.(row);
  return <Modal title={"Detail " + config.title} className="order-editor-modal" onClose={close}>
    <div className="po-form-body">
      {detail.loading && <p className="form-hint" role="status">Memuat detail...</p>}
      {detail.error && <p className="form-error" role="alert">{detail.error}</p>}
      <div className="detail-grid">{[...config.columns, ...(config.details ?? [])].map(column =>
        <p key={column.key}>{column.label}<strong><Cell row={row} column={column}/></strong></p>)}</div>
      {config.groups?.map(group => {
        const rows = records(row, group.key);
        return rows.length > 0 && <section key={group.key}>
          <h3>{group.title}</h3>
          <div className="table-scroll"><table>
            <thead><tr>{group.columns.map(column => <th key={column.key}>{column.label}</th>)}</tr></thead>
            <tbody>{rows.map((line, index) => <tr key={line.id ?? index}>{group.columns.map(column =>
              <td key={column.key}><Cell row={line} column={column}/></td>)}</tr>)}</tbody>
          </table></div>
        </section>;
      })}
    </div>
    <div className="modal-actions api-actions">
      {config.endpoint === "documents" && Boolean(row.file_name) && <a className="button secondary" href={"/api/documents/" + row.id + "/download"}>Unduh berkas</a>}
      {config.print && <Button variant="secondary" onClick={() => preview(row)}>Surat PO</Button>}
      {canEdit && <Button variant="secondary" onClick={() => edit(row)}>Edit</Button>}
      {canRemove && <Button variant="secondary" onClick={() => act({ label: "Hapus", method: "DELETE" }, row)}>Hapus</Button>}
      {operations.map(operation => <Button key={operation.suffix} variant="secondary" onClick={() => act(operation, row)}>{operation.label}</Button>)}
    </div>
  </Modal>;
}

function OperationDialog({ row, config, operation, close, done }: {
  row: Row; config: ApiModule; operation: Operation; close: () => void; done: () => void;
}) {
  const [reason, setReason] = useState("");
  const [target, setTarget] = useState(quantityInput(row.stock_quantity));
  const [idempotencyKey] = useState(() => operation.adjustment ? crypto.randomUUID() : "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    try {
      const path = [config.endpoint, row.id, operation.suffix].filter(Boolean).join("/");
      const body = operation.adjustment
        ? { expected_quantity: String(row.stock_quantity), target_quantity: target.trim(), reason: reason.trim() }
        : operation.reason ? { reason: reason.trim() } : undefined;
      await api(path, {
        method: operation.method ?? "POST", body,
        headers: idempotencyKey ? { "Idempotency-Key": idempotencyKey } : undefined,
      });
      done();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Aksi gagal diproses.");
      setBusy(false);
    }
  }

  return <Modal title={operation.label} onClose={() => { if (!busy) close(); }}>
    <form className="order-form company-form" onSubmit={event => void submit(event)}>
      <p>{operation.method === "DELETE" ? "Hapus data " : "Konfirmasi aksi untuk "}{text(row, config.columns[0])}?</p>
      {operation.adjustment && <>
        <p>Saldo saat ini {formatQuantity(row.stock_quantity)} {String(row.unit)}</p>
        <label>Saldo yang benar<input required inputMode="decimal" pattern="(?:0|[1-9][0-9]*)(?:[.][0-9]{1,3})?" value={target} onChange={event => setTarget(event.target.value)}/></label>
      </>}
      {(operation.reason || operation.adjustment) && <label>Alasan<textarea required minLength={5} maxLength={operation.adjustment ? 1000 : 2000}
        value={reason} onChange={event => setReason(event.target.value)}/></label>}
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="modal-actions"><Button type="button" variant="secondary" disabled={busy} onClick={close}>Kembali</Button><Button type="submit" disabled={busy}>{busy ? "Memproses..." : operation.label}</Button></div>
    </form>
  </Modal>;
}

type Selection = { mode: "create" }
  | { mode: "detail" | "edit" | "preview"; row: Row }
  | { mode: "operation"; row: Row; operation: Operation };

export function ApiModuleView({ slug }: { slug: string }) {
  const config = apiModules[slug];
  const user = useSession();
  const create = config.mutations?.create;
  const canCreate = !!config.fields && (typeof create === "function" ? create(user) : !!create && user.permissions.includes(create));
  const params = useSearchParams();
  const [revision, setRevision] = useState(0);
  const search = params.get("q") ?? "";
  const filter = params.get("status") ?? "";
  const [queryState, setQuery] = useState({ search, value: search });
  const [statusState, setStatus] = useState({ filter, value: filter });
  const query = queryState.search === search ? queryState.value : search;
  const status = statusState.filter === filter ? statusState.value : filter;
  const [selection, setSelection] = useState<Selection | null>(null);
  const [notice, setNotice] = useState("");
  const { data, loading, error } = useApiData<Row[]>(config.endpoint, revision, true);
  const rows = config.filter ? (data ?? []).filter(config.filter) : data ?? [];
  const statusKey = config.status;
  const choices = statusKey ? [...new Set(rows.map(row => String(get(row, statusKey))).concat(status ? [status] : []))] : [];
  const filtered = rows.filter(row =>
    (!status || (statusKey && String(get(row, statusKey)) === status))
    && config.columns.some(column => text(row, column).toLowerCase().includes(query.trim().toLowerCase())));
  const pagination = usePagination(filtered, JSON.stringify([slug, query, status]));

  function refresh() { setRevision(current => current + 1); }
  function saved() {
    setNotice("Perubahan berhasil disimpan.");
    setSelection(null);
    refresh();
  }

  return <>
    <div className="page-heading"><div>
      <div className="eyebrow"><span className="status-dot"/> WORKSPACE / OPERASIONAL</div>
      <h1>{config.title}</h1><p>{config.description}</p>
    </div><div className="heading-actions">
      <ExportButton disabled={loading || !!error} filename={slug + ".csv"} columns={config.columns.map(column => column.label)}
        records={filtered.map(row => ({ date: datePart(get(row, config.date)), cells: config.columns.map(column => {
          const value = get(row, column.key);
          return typeof value === "string" && column.format === "money" ? value : text(row, column);
        }) }))}/>
      {canCreate && <Button disabled={slug !== "purchase-orders" && (loading || !!error)} onClick={() => { setNotice(""); setSelection({ mode: "create" }); }}>
        <Icon name="plus" size={17}/>{slug === "purchase-orders" ? "Buat Purchase Order" : "Tambah " + config.title}
      </Button>}
    </div></div>
    {notice && <p className="form-hint" role="status">{notice}</p>}
    <section className="panel module-panel">
      <div className="panel-header"><div><h2>Daftar {config.title} <span className="count-pill">{filtered.length}</span></h2></div>
        <Button variant="secondary" disabled={loading} onClick={refresh}>Muat ulang</Button></div>
      <div className="table-toolbar">
        <label className="table-search"><Icon name="search" size={18}/><input aria-label={"Cari " + config.title} placeholder="Cari data..." value={query} onChange={event => setQuery({ search, value: event.target.value })}/></label>
        {statusKey && <select aria-label="Filter status" value={status} onChange={event => setStatus({ filter, value: event.target.value })}>
          <option value="">Semua status</option>{choices.map(value => <option key={value} value={value}>{statusText(value === "true" ? true : value === "false" ? false : value, statusKey)}</option>)}
        </select>}
      </div>
      {loading ? <p className="loading-state" role="status">Memuat data...</p> : error ? <p className="form-error" role="alert">{error}</p> : filtered.length ? <>
        <div className="table-scroll"><table>
          <thead><tr>{config.columns.map(column => <th key={column.key}>{column.label}</th>)}<th>Aksi</th></tr></thead>
          <tbody>{pagination.items.map(row => <tr key={row.id}>{config.columns.map(column => <td key={column.key}><Cell row={row} column={column}/></td>)}
            <td><Button variant="secondary" onClick={() => { setNotice(""); setSelection({ mode: "detail", row }); }}>Lihat</Button></td>
          </tr>)}</tbody>
        </table></div>
        <Pagination {...pagination} label={config.title}/>
      </> : <div className="empty-state"><Icon name="file" size={32}/><h3>Tidak ada data</h3><p>Data yang cocok dengan filter akan muncul di sini.</p></div>}
    </section>
    {canCreate && selection?.mode === "create" && (slug === "purchase-orders"
      ? <CreateOrderModal onClose={() => setSelection(null)} onSaved={saved}/>
      : <ApiRecordEditor config={config} close={() => setSelection(null)} saved={saved}/>)}
    {selection?.mode === "edit" && (slug === "purchase-orders"
      ? <CreateOrderModal row={selection.row} onClose={() => setSelection({ mode: "detail", row: selection.row })} onSaved={saved}/>
      : <ApiRecordEditor config={config} row={selection.row}
        close={() => setSelection({ mode: "detail", row: selection.row })} saved={saved}/>)}
    {selection?.mode === "detail" && <Details row={selection.row} config={config} close={() => setSelection(null)}
      edit={row => setSelection({ mode: "edit", row })}
      act={(operation, row) => setSelection({ mode: "operation", row, operation })}
      preview={row => setSelection({ mode: "preview", row })}/>}
    {selection?.mode === "operation" && <OperationDialog row={selection.row} config={config} operation={selection.operation}
      close={() => setSelection({ mode: "detail", row: selection.row })} done={saved}/>}
    {selection?.mode === "preview" && <ApiPurchaseOrderPreview row={selection.row}
      close={() => setSelection({ mode: "detail", row: selection.row })}/>}
  </>;
}
