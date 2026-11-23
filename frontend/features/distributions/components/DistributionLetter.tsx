"use client";

import { PrintPreview } from "@/components/ui/PrintPreview";
import { datePart, get, records, type Row } from "@/features/modules/api-types";
import { formatQuantity } from "@/lib/quantity";

export function DistributionLetter({ row, close }: { row: Row; close: () => void }) {
  const number = String(row.distribution_number);
  const recipient = String(row.recipient_name ?? get(row, "employee.name") ?? "—");
  const department = String(get(row, "employee.department_name") ?? "—");
  const date = datePart(row.sent_at).split("-").reverse().join("/");

  return <PrintPreview title={"Surat Jalan - " + number} onClose={close}>
    <article className="po-letter distribution-letter">
      <header className="distribution-letter-header">
        <h1>Surat Jalan</h1><p>{number}</p>
      </header>
      <div className="po-parties">
        <div><p className="po-caption">Penerima</p><strong>{recipient}</strong></div>
        <div><p className="po-caption">Tanggal penyerahan</p><strong>{date || "—"}</strong></div>
        <div><p className="po-caption">Departemen</p><strong>{department}</strong></div>
        <div><p className="po-caption">Keperluan</p><strong>{String(row.purpose ?? "—")}</strong></div>
      </div>
      <table className="po-lines distribution-letter-lines">
        <thead><tr><th>SKU</th><th>Barang</th><th>Jumlah</th><th>Catatan</th></tr></thead>
        <tbody>{records(row, "items").map(line => <tr key={line.id}>
          <td>{String(line.sku ?? "—")}</td>
          <td>{String(line.name ?? "—")}</td>
          <td>{formatQuantity(line.quantity)} {String(line.unit ?? "")}</td>
          <td>{String(line.notes || "—")}</td>
        </tr>)}</tbody>
      </table>
      <div className="distribution-letter-signatures">
        <div><p>Diserahkan oleh</p><p>{String(row.sender_name ?? "Nama dan tanda tangan")}</p></div>
        <div><p>Diterima oleh</p><p>{recipient}</p></div>
      </div>
    </article>
  </PrintPreview>;
}
