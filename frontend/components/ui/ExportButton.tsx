"use client";

import { useState } from "react";
import { exportCsv } from "@/lib/utils";
import { exportRange, filterExportRecords, validDate, workspaceToday, type ExportPeriod, type ExportRecord } from "@/lib/export-period";
import { Button } from "./Button";
import { Icon } from "./Icon";
import { Modal } from "./Modal";

type Props = { filename: string; columns: string[]; records: ExportRecord[]; disabled?: boolean; dateLabel?: string; label?: string };

function ExportDialog({ filename, columns, records, dateLabel = "Tanggal pencatatan", close, today }: Props & { close: () => void; today: string }) {
  const [period, setPeriod] = useState<ExportPeriod>("week");
  const [start, setStart] = useState(today);
  const [end, setEnd] = useState(today);
  const [downloadError, setDownloadError] = useState("");
  let error = "";
  let range = null;
  try { range = exportRange(period, { start, end }, today); }
  catch (cause) { error = (cause as Error).message; }
  const filtered = error ? [] : filterExportRecords(records, range);
  const undated = records.filter(record => !record.date || !validDate(record.date)).length;

  return <Modal title="Ekspor data" onClose={close}>
    <form className="order-form company-form" onSubmit={event => {
      event.preventDefault();
      if (error || !filtered.length) return;
      try {
        const suffix = range ? `_${range.start}_${range.end}` : "";
        exportCsv(`${filename.replace(/\.csv$/i, "")}${suffix}.csv`, [columns, ...filtered.map(record => record.cells)]);
        close();
      } catch { setDownloadError("File gagal diunduh. Silakan coba lagi."); }
    }}>
      <label>Periode<select value={period} onChange={event => { setPeriod(event.target.value as ExportPeriod); setDownloadError(""); }}>
        <option value="week">7 hari terakhir</option><option value="month">1 bulan terakhir</option><option value="custom">Tanggal kustom</option><option value="all">Semua tanggal</option>
      </select></label>
      {period === "custom" && <div className="form-row"><label>Dari tanggal<input required type="date" value={start} onChange={event => setStart(event.target.value)}/></label><label>Sampai tanggal<input required type="date" min={start || undefined} value={end} onChange={event => setEnd(event.target.value)}/></label></div>}
      {range && !error && <p className="form-hint">{dateLabel}: {range.start} s.d. {range.end} (termasuk kedua tanggal).</p>}
      <p className="form-hint" role="status">{filtered.length} data siap diekspor. Mengikuti pencarian dan filter aktif, mencakup seluruh halaman tabel.</p>
      {period !== "all" && undated > 0 && <p className="form-hint">{undated} data tanpa tanggal tidak disertakan. Pilih Semua tanggal untuk menyertakannya.</p>}
      {!error && !filtered.length && <p className="form-hint">Tidak ada data pada periode ini. Pilih periode lain.</p>}
      {(error || downloadError) && <p className="form-error" role="alert">{error || downloadError}</p>}
      <div className="modal-actions"><Button type="button" variant="secondary" onClick={close}>Batal</Button><Button type="submit" disabled={!!error || !filtered.length}><Icon name="download" size={16}/> Unduh CSV</Button></div>
    </form>
  </Modal>;
}

export function ExportButton(props: Props) {
  const [today, setToday] = useState<string | null>(null);
  return <><Button variant="secondary" disabled={props.disabled} onClick={() => setToday(workspaceToday())}><Icon name="download" size={16}/> {props.label || "Ekspor CSV"}</Button>
    {today && <ExportDialog {...props} today={today} close={() => setToday(null)}/>}
  </>;
}
