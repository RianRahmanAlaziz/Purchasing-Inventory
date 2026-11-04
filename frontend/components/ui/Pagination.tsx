"use client";
import { useId } from "react";
import { paginationPages } from "@/lib/pagination";
import { Icon } from "./Icon";

type Props = {
  page: number; pageCount: number; pageSize: number; total: number; start: number; end: number;
  setPage: (page: number) => void; setPageSize: (size: number) => void;
  label?: string;
};
export function Pagination({ page, pageCount, pageSize, total, start, end, setPage, setPageSize, label = "tabel" }: Props) {
  const selectId = useId();
  return <div className="pagination">
    <p className="pagination-summary" role="status">Menampilkan <strong>{start}{total > 0 && <>–{end}</>}</strong> dari <strong>{total}</strong> data</p>
    <div className="pagination-options"><label htmlFor={selectId}>Baris per halaman</label><select id={selectId} value={pageSize} onChange={event => setPageSize(Number(event.target.value))}>{[5,10,25,50].map(size => <option key={size} value={size}>{size}</option>)}</select></div>
    <nav className="pagination-nav" aria-label={`Pagination ${label}`}>
      <button type="button" className="pagination-step previous" disabled={page === 1 || total === 0} onClick={() => setPage(page - 1)} aria-label="Halaman sebelumnya"><Icon name="chevron" size={14}/><span>Sebelumnya</span></button>
      <div className="pagination-pages">{paginationPages(page, pageCount).map((value,index) => value === "ellipsis" ? <span className="pagination-ellipsis" key={`ellipsis-${index}`} aria-hidden="true">…</span> : <button type="button" key={value} className={value === page ? "is-current" : ""} aria-label={`Halaman ${value}`} aria-current={value === page ? "page" : undefined} disabled={total === 0} onClick={() => setPage(value)}>{value}</button>)}</div>
      <button type="button" className="pagination-step next" disabled={page === pageCount || total === 0} onClick={() => setPage(page + 1)} aria-label="Halaman berikutnya"><span>Berikutnya</span><Icon name="chevron" size={14}/></button>
    </nav>
  </div>;
}
