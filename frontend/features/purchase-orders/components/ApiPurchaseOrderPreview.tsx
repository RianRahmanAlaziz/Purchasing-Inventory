"use client";

import { useState } from "react";
import Link from "next/link";
import { useCompanies } from "@/features/companies/hooks/useCompanies";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { OrderPreview } from "./OrderPreview";
import type { Row } from "@/features/modules/api-types";
import { snapshotCompany } from "../utils/api-draft";

export function ApiPurchaseOrderPreview({ row, close }: { row: Row; close: () => void }) {
  const { companies, activeId, ready } = useCompanies();
  const [companyId, setCompanyId] = useState<string | null>(null);
  const [preview, setPreview] = useState(false);
  const selected = companyId ?? activeId;
  const company = companies.find(entry => entry.id === selected);
  const savedCompany = snapshotCompany(row);

  if (savedCompany) return <OrderPreview row={row} company={savedCompany} onClose={close}/>;
  if (preview && company) return <OrderPreview row={row} company={company} onClose={() => setPreview(false)}/>;
  return <Modal title="Surat Purchase Order" onClose={close}>
    <div className="order-form">
      <p className="form-hint">PO lama belum memiliki PT penerbit. Pilih profil perusahaan untuk kop surat ini.</p>
      <label>PT penerbit<select value={selected || ""} disabled={!ready} onChange={event => setCompanyId(event.target.value)}>
        <option value="">Pilih PT penerbit</option>{companies.map(entry => <option key={entry.id} value={entry.id}>{entry.name}</option>)}
      </select></label>
      <Link className="text-link" href="/companies">Kelola profil perusahaan</Link>
      <div className="modal-actions"><Button variant="secondary" onClick={close}>Kembali</Button><Button disabled={!company} onClick={() => setPreview(true)}>Buka pratinjau</Button></div>
    </div>
  </Modal>;
}
