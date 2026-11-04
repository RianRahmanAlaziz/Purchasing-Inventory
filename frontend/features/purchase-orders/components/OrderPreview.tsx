"use client";
import { createPortal } from "react-dom";
import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import { documentMoney, lineTotals, orderTotals } from "../utils/totals";
import type { PurchaseOrder } from "../types";

const dateLabel = (date?: string) => date ? new Date(`${date}T00:00:00`).toLocaleDateString("id-ID") : "-";
export function PurchaseOrderLetter({ order, paginate = true }: { order: PurchaseOrder; paginate?: boolean }) {
  const lines = order.lines || (order.itemName ? [{ id: "legacy", description: order.itemName, quantity: order.quantity || 1, unit: "", unitPrice: order.unitPrice || order.amount, discountPercent: 0, taxPercent: 0 }] : []);
  const totals = orderTotals(lines);
  const company = order.company;
  const pagination = usePagination(lines, order.id);
  const visibleLines = paginate ? pagination.items : lines;
  return <article className="po-letter"><header className="po-letterhead">{company?.logo && <img className="po-logo" src={company.logo} alt={`Logo ${company.name}`}/>}<div><h2>{company?.name || "Identitas PT belum ditentukan"}</h2>{company?.address && <p>{company.address}</p>}{(company?.phone || company?.email) && <p>{[company.phone, company.email].filter(Boolean).join(" | ")}</p>}</div></header>
    <div className="po-parties"><div><h1>SPB #{order.id}</h1><p className="po-caption">Pembeli</p><strong>{company?.name || "-"}</strong></div><div className="po-supplier"><strong>{order.supplier}</strong>{order.supplierAddress && <p>{order.supplierAddress}</p>}<div className="po-dates"><div><span>Tanggal Pemesanan:</span><strong>{dateLabel(order.date)}</strong></div><div><span>Kedatangan yang Diharapkan:</span><strong>{dateLabel(order.expectedDate)}</strong></div></div></div></div>
    <table className="po-lines"><thead><tr><th>Deskripsi</th><th>Jml</th><th>Harga Satuan</th><th>Disc.</th><th>Pajak</th><th>Jumlah</th></tr></thead><tbody>{visibleLines.map(line => <tr key={line.id}><td>{line.description}</td><td>{line.quantity.toLocaleString("id-ID", { minimumFractionDigits: 2 })}{line.unit && <small>{line.unit}</small>}</td><td>{documentMoney(line.unitPrice)}</td><td>{line.discountPercent.toLocaleString("id-ID")}%</td><td>{line.taxPercent.toLocaleString("id-ID")}%</td><td>{documentMoney(lineTotals(line).total)}</td></tr>)}{!lines.length && <tr><td colSpan={5}>Rincian barang belum tersedia pada data PO lama.</td><td>{documentMoney(order.amount)}</td></tr>}</tbody></table>
    {paginate && lines.length > 0 && <Pagination {...pagination} label="rincian barang PO"/>}
    <div className="po-totals">{lines.length > 0 && <><p><span>Subtotal</span><strong>{documentMoney(totals.subtotal)}</strong></p>{totals.discount > 0 && <p><span>Diskon</span><strong>- {documentMoney(totals.discount)}</strong></p>}{totals.tax > 0 && <p><span>Pajak</span><strong>{documentMoney(totals.tax)}</strong></p>}</>}<p className="po-grand-total"><span>Total</span><strong>{documentMoney(lines.length ? totals.total : order.amount)}</strong></p></div>
    <div className="po-terms"><p><strong>Termin Pembayaran:</strong> {order.paymentTerms || "-"}</p>{order.notes && <p><strong>Catatan:</strong> {order.notes}</p>}</div>
  </article>;
}

export function OrderPreview({ order, onClose }: { order: PurchaseOrder; onClose: () => void }) {
  const [printing, setPrinting] = useState(false);
  async function print() {
    setPrinting(true);
    try {
      await Promise.all(Array.from(document.querySelectorAll<HTMLImageElement>(".po-print-root img")).map(img => img.decode().catch(() => {})));
      await document.fonts.ready;
      window.print();
    } finally { setPrinting(false); }
  }
  return <Modal title={`Surat PO - ${order.id}`} className="po-preview-modal" onClose={onClose}><div className="po-preview-toolbar"><p>Pratinjau surat purchase order</p><Button variant="primary" disabled={!order.company || printing} onClick={() => void print()}><Icon name="file" size={16}/>{printing ? "Menyiapkan..." : "Cetak / Simpan PDF"}</Button></div>{!order.company && <p className="form-error" role="status">PO lama ini belum memiliki identitas PT. Buat PO baru dan pilih PT penerbit untuk mencetak surat lengkap.</p>}<div className="po-paper-scroll"><PurchaseOrderLetter order={order}/></div><p className="form-hint">Pilih kertas A4 dan matikan header/footer browser pada dialog cetak untuk hasil yang rapi.</p>{createPortal(<div className="po-print-root"><PurchaseOrderLetter order={order} paginate={false}/></div>, document.body)}</Modal>;
}
