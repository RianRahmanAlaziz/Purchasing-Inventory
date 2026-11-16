"use client";

import { PrintPreview } from "@/components/ui/PrintPreview";
import type { Company } from "@/features/companies/types";
import { datePart, records, type Row } from "@/features/modules/api-types";
import { documentMoney } from "../utils/totals";
import { formatQuantity } from "@/lib/quantity";

type Props = { row: Row; company: Company };

const approvalDate = new Intl.DateTimeFormat("id-ID", {
  dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta",
});

const approvalNotes: Record<string, string> = {
  pending_approval: "PO menunggu persetujuan",
  rejected: "PO ditolak",
  cancelled: "PO dibatalkan",
};

function PurchaseOrderLetter({ row, company }: Props) {
  const lines = records(row, "items");
  const approvals = records(row, "approvals").filter(approval => approval.status === "approved");
  const approvalNote = approvalNotes[String(row.status)];
  const supplierAddress = typeof row.supplier_address === "string" ? row.supplier_address : row.supplier_address_snapshot;
  const dateLabel = (value: unknown) => datePart(value).split("-").reverse().join("/") || "—";
  const money = (value: unknown) => documentMoney(String(value));

  return (
    <article className="po-letter">
      <header className="po-letterhead">
        {company.logo && <img className="po-logo" src={company.logo} alt={"Logo " + company.name} />}
        <div>
          <h2>{company.name}</h2>
          {company.address && <p>{company.address}</p>}
          {(company.phone || company.email) && <p>{[company.phone, company.email].filter(Boolean).join(" | ")}</p>}
        </div>
      </header>
      <div className="po-parties">
        <h1>SPB #{String(row.po_number)}</h1>
        <div>
          <p className="po-caption">Pembeli</p>
          <strong>{company.name}</strong>
        </div>
        <div>
          <p className="po-caption">Supplier</p>
          <strong>{String(row.supplier_name_snapshot ?? "—")}</strong>
          {typeof supplierAddress === "string" && supplierAddress.trim() && (
            <p className="po-supplier-address">{supplierAddress}</p>
          )}
        </div>
        <div className="po-letter-fields">
          <div>
            <span>Karyawan pemohon</span>
            <strong>{String(row.requested_for_employee_name ?? "—")}</strong>
          </div>
          <div>
            <span>Departemen</span>
            <strong>{String(row.requested_for_department_name ?? "—")}</strong>
          </div>
        </div>
        <div className="po-letter-fields">
          <div><span>Tanggal pemesanan</span><strong>{dateLabel(row.order_date)}</strong></div>
          <div><span>Perkiraan datang</span><strong>{dateLabel(row.expected_date)}</strong></div>
        </div>
      </div>
      <table className="po-lines">
        <thead>
          <tr>
            <th>Barang</th>
            <th>Jumlah</th>
            <th>Harga satuan</th>
            <th>Diskon</th>
            <th>Pajak</th>
            <th>Subtotal</th>
          </tr>
        </thead>
        <tbody>
          {lines.map(line =>
            <tr key={line.id}>
              <td>{String(line.name)}</td>
              <td>{formatQuantity(line.quantity)} {String(line.unit ?? "").trim()}</td>
              <td>{money(line.unit_price)}</td>
              <td>{Number(line.discount_percent ?? 0)}%</td>
              <td>{line.tax_rate_id ? (line.tax_direction === "withholding" ? "− " : "") + money(line.tax_amount) : "—"}</td><td>{money(line.line_subtotal)}</td></tr>)}</tbody>
      </table>
      <div className="po-totals">
        <p>
          <span>Subtotal</span>
          <strong>{money(row.gross_subtotal ?? row.subtotal)}</strong>
        </p>
        {Number(row.discount_total) > 0 && <p><span>Diskon</span><strong>− {money(row.discount_total)}</strong></p>}
        {Number(row.tax_addition_total) > 0 && <p><span>Pajak tambahan</span><strong>{money(row.tax_addition_total)}</strong></p>}
        {Number(row.tax_withholding_total) > 0 && <p><span>Potongan pajak</span><strong>{money(row.tax_withholding_total)}</strong></p>}
        <p className="po-grand-total">
          <span>Total</span>
          <strong>{money(row.total_amount)}</strong>
        </p>
      </div>
      {Boolean(row.notes) && <div className="po-terms"><p><strong>Catatan</strong> {String(row.notes)}</p></div>}
      {approvals.length > 0 && (
        <div className="po-approval-history">
          {approvalNote && <p>{approvalNote}</p>}

          <table aria-label="Riwayat persetujuan PO">
            <thead>
              <tr>
                <th colSpan={4} scope="colgroup" className="approval-title">
                  Proses Persetujuan
                </th>
              </tr>
            </thead>

            <tbody>
              {approvals.map((approval) => {
                const decidedAt = String(approval.decided_at ?? "");
                const date = new Date(decidedAt);
                return (
                  <tr key={approval.id}>
                    <th scope="row">Penyetuju</th>
                    <td>{String(approval.decided_by_name ?? "—")}</td>
                    <td>Disetujui</td>
                    <td>
                      {Number.isNaN(date.valueOf()) ? (
                        "—"
                      ) : (
                        <time dateTime={decidedAt}>
                          {approvalDate.format(date)}
                        </time>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </article>
  );
}

export function OrderPreview({ row, company, onClose }: Props & { onClose: () => void }) {
  return (
    <PrintPreview title={"Surat PO - " + row.po_number} onClose={onClose}>
      <PurchaseOrderLetter row={row} company={company} />
    </PrintPreview>
  );
}
