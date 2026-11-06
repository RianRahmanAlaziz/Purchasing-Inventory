"use client";

import { createPortal } from "react-dom";
import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import type { Company } from "@/features/companies/types";
import { datePart, records, type Row } from "@/features/modules/api-types";
import { documentMoney } from "../utils/totals";
import { formatQuantity } from "@/lib/quantity";

type Props = { row: Row; company: Company };

function PurchaseOrderLetter({ row, company, paginate }: Props & { paginate: boolean }) {
  const lines = records(row, "items");
  const pagination = usePagination(lines, String(row.id));
  const visible = paginate ? pagination.items : lines;
  const dateLabel = (value: unknown) => datePart(value).split("-").reverse().join("/") || "—";
  const money = (value: unknown) => documentMoney(String(value));

  return <article className="po-letter">
    <header className="po-letterhead">{company.logo && <img className="po-logo" src={company.logo} alt={"Logo " + company.name}/>}
      <div><h2>{company.name}</h2>{company.address && <p>{company.address}</p>}
        {(company.phone || company.email) && <p>{[company.phone, company.email].filter(Boolean).join(" | ")}</p>}
      </div>
    </header>
    <div className="po-parties">
      <div><h1>SPB #{String(row.po_number)}</h1><p className="po-caption">Pembeli</p><strong>{company.name}</strong></div>
      <div className="po-supplier"><strong>{String(row.supplier_name_snapshot)}</strong>
        {Boolean(row.supplier_address_snapshot) && <p>{String(row.supplier_address_snapshot)}</p>}
        <div className="po-dates"><div><span>Tanggal pemesanan</span><strong>{dateLabel(row.order_date)}</strong></div>
          <div><span>Perkiraan datang</span><strong>{dateLabel(row.expected_date)}</strong></div></div>
      </div>
    </div>
    <table className="po-lines"><thead><tr><th>Barang</th><th>Jumlah</th><th>Harga satuan</th><th>Diskon</th><th>Pajak</th><th>Subtotal</th></tr></thead>
      <tbody>{visible.map(line => <tr key={line.id}><td>{String(line.name)}</td><td>{formatQuantity(line.quantity)} <small>{String(line.unit)}</small></td>
        <td>{money(line.unit_price)}</td><td>{Number(line.discount_percent ?? 0)}%</td>
        <td>{line.tax_rate_id ? (line.tax_direction === "withholding" ? "− " : "") + money(line.tax_amount) : "—"}</td><td>{money(line.line_subtotal)}</td></tr>)}</tbody>
    </table>
    {paginate && lines.length > 0 && <Pagination {...pagination} label="rincian barang PO"/>}
    <div className="po-totals">
      <p><span>Subtotal</span><strong>{money(row.gross_subtotal ?? row.subtotal)}</strong></p>
      {Number(row.discount_total) > 0 && <p><span>Diskon</span><strong>− {money(row.discount_total)}</strong></p>}
      {Number(row.tax_addition_total) > 0 && <p><span>Pajak tambahan</span><strong>{money(row.tax_addition_total)}</strong></p>}
      {Number(row.tax_withholding_total) > 0 && <p><span>Potongan pajak</span><strong>{money(row.tax_withholding_total)}</strong></p>}
      <p className="po-grand-total"><span>Total</span><strong>{money(row.total_amount)}</strong></p>
    </div>
    {Boolean(row.notes) && <div className="po-terms"><p><strong>Catatan</strong> {String(row.notes)}</p></div>}
  </article>;
}

export function OrderPreview({ row, company, onClose }: Props & { onClose: () => void }) {
  const [printing, setPrinting] = useState(false);

  async function print() {
    setPrinting(true);
    try {
      await Promise.all(Array.from(document.querySelectorAll<HTMLImageElement>(".po-print-root img"))
        .map(img => img.decode().catch(() => {})));
      await document.fonts.ready;
      window.print();
    } finally { setPrinting(false); }
  }

  return <Modal title={"Surat PO - " + row.po_number} className="po-preview-modal" onClose={onClose}>
    <div className="po-preview-toolbar"><p>Pratinjau surat purchase order</p>
      <Button disabled={printing} onClick={() => void print()}><Icon name="file" size={16}/>{printing ? "Menyiapkan..." : "Cetak / Simpan PDF"}</Button>
    </div>
    <div className="po-paper-scroll"><PurchaseOrderLetter row={row} company={company} paginate/></div>
    {createPortal(<div className="po-print-root"><PurchaseOrderLetter row={row} company={company} paginate={false}/></div>, document.body)}
  </Modal>;
}
