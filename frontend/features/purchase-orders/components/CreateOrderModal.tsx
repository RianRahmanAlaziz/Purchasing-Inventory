"use client";

import { useState, type FormEvent } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { useCompanies } from "@/features/companies/hooks/useCompanies";
import { useSession } from "@/features/auth/Session";
import { useApiData } from "@/features/modules/hooks/useApiData";
import { get, records, type Row } from "@/features/modules/api-types";
import { api } from "@/lib/api";
import { workspaceToday } from "@/lib/export-period";
import { PoAutocomplete, type PoSuggestion } from "./PoAutocomplete";
import { documentMoney, lineTotals, orderTotals } from "../utils/totals";
import { apiLine, emptyLine, legacyLine, poPayload, readBrowserDraft, snapshotCompany, type DraftLine, type TaxOption } from "../utils/api-draft";
import { ITEM_UNITS } from "@/lib/item-units";

const percent = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 4 });
type Props = { row?: Row; onClose: () => void; onSaved: () => void };

export function CreateOrderModal(props: Props) {
  const [revision, setRevision] = useState(0);
  const detail = useApiData<Row>(props.row ? "purchase-orders/" + props.row.id : null, revision);
  if (props.row && !detail.data) return <Modal title="Edit Purchase Order" onClose={props.onClose}>
    {detail.loading ? <p className="loading-state" role="status">Memuat purchase order...</p>
      : <><p className="form-error" role="alert">{detail.error || "PO tidak dapat dimuat."}</p><Button onClick={() => setRevision(value => value + 1)}>Coba lagi</Button></>}
  </Modal>;
  return <OrderForm {...props} row={detail.data ?? undefined}/>;
}

function OrderForm({ row, onClose, onSaved }: Props) {
  const user = useSession();
  const storageKey = "ris-po-form-draft-" + user.id;
  const [restored] = useState(() => row ? { draft: undefined, error: "" } : readBrowserDraft(storageKey));
  const legacy = restored.draft;
  const { companies, activeId, ready: companiesReady, error: companyError } = useCompanies();
  const canManageItems = user.permissions.includes("items.manage");
  const categories = useApiData<Row[]>(canManageItems ? "purchase-orders/suggestions/categories" : null, 0, true);
  const employees = useApiData<Row[]>(user.permissions.includes("employees.view") ? "employees?is_active=1" : null, 0, true);
  const savedCompany = snapshotCompany(row);
  const [companyChoice, setCompany] = useState<string | null>(row?.company_id ? String(row.company_id) : null);
  const legacyCompany = legacy?.company?.name
    ? companies.find(company => company.name.trim().replace(/\s+/g, " ").toLowerCase() === legacy.company!.name.trim().replace(/\s+/g, " ").toLowerCase())
    : undefined;
  const companyId = companyChoice ?? (legacy?.company ? legacyCompany?.id ?? "" : activeId);
  const company = savedCompany?.id === companyId ? savedCompany : companies.find(entry => entry.id === companyId);
  const [supplierId, setSupplierId] = useState(String(row?.supplier_id ?? ""));
  const [supplierName, setSupplierName] = useState(String(row?.supplier_name_snapshot ?? legacy?.supplier ?? ""));
  const [supplierAddress, setSupplierAddress] = useState(String(row?.supplier_address_snapshot ?? legacy?.supplierAddress ?? ""));
  const [employeeId, setEmployee] = useState(String(row?.requested_for_employee_id ?? user.employee_id ?? ""));
  const employee = employees.data?.find(entry => String(entry.id) === employeeId);
  const departmentId = String(get(employee, "department.id") ?? "");
  const [date, setDate] = useState(String(row?.order_date ?? legacy?.date ?? workspaceToday()));
  const [expectedDate, setExpectedDate] = useState(String(row?.expected_date ?? legacy?.expectedDate ?? ""));
  const [notes, setNotes] = useState(String(row?.notes ?? legacy?.notes ?? ""));
  const [lines, setLines] = useState<DraftLine[]>(() => row ? records(row, "items").map(apiLine) : legacy?.lines?.map(legacyLine) ?? [emptyLine()]);
  const taxScope = row?.tax_scope === "order" ? "order" : "line";
  const [orderRateIds, setOrderRates] = useState(() => records(row, "taxes").map(rate => String(rate.tax_rate_id)));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(restored.error);
  const canReadTaxes = user.permissions.includes("taxes.view");
  const taxes = useApiData<TaxOption[]>(canReadTaxes && date ? "taxes/options?date=" + encodeURIComponent(date) : null);
  const rates = taxes.data ?? [];
  const unlinkedItems = lines.some(line => !line.itemId && line.itemName.trim());
  const masterLoading = employees.loading || (unlinkedItems && categories.loading);
  const taxPending = taxes.loading && (taxScope === "line" ? lines.some(line => !!line.taxRateId) : orderRateIds.length > 0);
  const resolvedLines = lines;
  const chosenOrderRates = orderRateIds.map(id => rates.find(rate => String(rate.id) === id) ?? (() => {
    const saved = records(row, "taxes").find(rate => String(rate.tax_rate_id) === id);
    return saved ? { id: Number(id), label: String(saved.name), rate_percent: String(saved.rate_percent),
      taxable_base_factor: String(saved.base_factor), tax_type: { direction: saved.direction as "addition" | "withholding" } } : undefined;
  })()).filter((rate): rate is TaxOption => !!rate);
  const pricedLines = resolvedLines.map(line => {
    const rate = rates.find(rate => String(rate.id) === line.taxRateId) ?? line.taxOption;
    return { id: line.id, description: line.description, unit: line.unit, quantity: Number(line.quantity), unitPrice: Number(line.unitPrice),
      discountPercent: Number(line.discountPercent), taxPercent: taxScope === "line" && line.taxRateId ? Number(rate?.rate_percent ?? 0) : 0,
      taxRate: taxScope === "line" && rate ? { id: rate.id, label: rate.label, direction: rate.tax_type.direction, baseFactor: Number(rate.taxable_base_factor) } : undefined };
  });
  const baseTotals = orderTotals(pricedLines);
  const orderTax = taxScope === "order" ? chosenOrderRates.reduce((sum, rate) => sum + lineTotals({
    id: "", description: "", unit: "", quantity: 1, unitPrice: baseTotals.subtotal - baseTotals.discount, discountPercent: 0,
    taxPercent: Number(rate.rate_percent), taxRate: { id: rate.id, label: rate.label, direction: rate.tax_type.direction, baseFactor: Number(rate.taxable_base_factor) },
  }).tax, 0) : 0;
  const totals = { ...baseTotals, tax: baseTotals.tax + orderTax, total: baseTotals.total + orderTax };
  const missingPermissions = ["companies.view", "suppliers.view", "items.view", "employees.view"].filter(permission => !user.permissions.includes(permission));
  const lookupError = companyError || employees.error || (unlinkedItems ? categories.error : "")
    || (missingPermissions.length ? "Izin master data belum lengkap: " + missingPermissions.join(", ") + "." : "");

  function updateLine(id: string, patch: Partial<DraftLine>) {
    setLines(current => current.map(line => line.id === id ? { ...line, ...patch } : line));
  }
  function selectItem(line: DraftLine, item: PoSuggestion) {
    updateLine(line.id, { itemId: String(item.id), itemName: item.name, description: item.name,
      categoryId: String(item.category_id ?? ""), unit: item.unit ?? "pcs", stockUnit: item.unit,
      unitPrice: line.unitPrice || (item.source === "history" || Number(item.unit_price) > 0 ? String(item.unit_price ?? "") : "") });
  }
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setError("");
    try {
      if (!companiesReady || !companies.some(entry => entry.id === companyId)) throw new Error("Pilih PT penerbit yang tersedia.");
      if (row && row.status !== "draft") throw new Error("Hanya PO Draft yang dapat diedit.");
      const selected = taxScope === "line" ? resolvedLines.map(line => line.taxRateId).filter(Boolean) : orderRateIds;
      if (selected.length && (taxes.loading || !taxes.data || taxes.error)) throw new Error("Tunggu tarif pajak selesai dimuat atau pilih Tanpa pajak.");
      if (selected.some(id => !rates.some(rate => String(rate.id) === id))) throw new Error("Pilih ulang pajak yang berlaku untuk tanggal pemesanan.");
      if (totals.total < 0) throw new Error("Pajak pemotong tidak boleh membuat total PO negatif.");
      const body = poPayload({ companyId, supplierId, supplierName, employeeId, departmentId, supplierAddress, date, expectedDate, notes,
        lines: resolvedLines, taxScope, orderRateIds });
      setBusy(true);
      await api(row ? "purchase-orders/" + row.id : "purchase-orders", { method: row ? "PATCH" : "POST", body });
      if (legacy) { try { localStorage.removeItem(storageKey); } catch { /* The server copy is already committed. */ } }
      onSaved();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "PO gagal disimpan."); setBusy(false); }
  }

  return <Modal title={row ? "Edit Purchase Order" : "Buat Purchase Order"} className="order-editor-modal po-document-editor" onClose={() => { if (!busy) onClose(); }}>
    <form className="order-form" aria-busy={busy} onSubmit={event => void submit(event)}>
      <div className="po-form-body" inert={busy}>
        {legacy && <p className="form-hint">Draft lama dimuat. Lengkapi isian untuk menyimpannya sebagai PO.</p>}
        <div className="po-company-picker"><label>
          <select aria-label="PT penerbit" required disabled={!companiesReady} value={companyId} onChange={event => setCompany(event.target.value)}>
            <option value="">Pilih PT penerbit</option>
            {savedCompany && !companies.some(entry => entry.id === savedCompany.id) && <option value={savedCompany.id} disabled>{savedCompany.name} (tersimpan)</option>}
            {companies.map(entry => <option key={entry.id} value={entry.id}>{entry.name}</option>)}
          </select>
        </label></div>
        {company && <div className="selected-company">
          {company.logo && <img src={company.logo} alt={"Logo " + company.name}/>}
          <div><strong>{company.name}</strong><small>{company.address || "Alamat belum diisi"}</small></div>
        </div>}
        <div className="form-row">
          <PoAutocomplete label="Supplier" kind="suppliers" value={supplierName} disabled={busy}
            onChange={name => { setSupplierName(name); setSupplierId(""); }}
            onSelect={option => { setSupplierName(option.name); setSupplierId(String(option.id)); setSupplierAddress(option.address ?? ""); }}/>
          <label>Alamat supplier<textarea rows={3} maxLength={5000} value={supplierAddress} placeholder="Opsional" onChange={event => setSupplierAddress(event.target.value)}/></label>
        </div>
        <div className="form-row">
          <label>Tanggal pemesanan<input type="date" required value={date} onChange={event => setDate(event.target.value)}/></label>
          <label>Kedatangan yang diharapkan<input type="date" min={date} value={expectedDate} onChange={event => setExpectedDate(event.target.value)}/></label>
        </div>
        <div className="form-row">
          <label>Karyawan pemohon<select required value={employeeId} disabled={employees.loading} onChange={event => setEmployee(event.target.value)}>
            <option value="">{employees.loading ? "Memuat karyawan..." : "Pilih karyawan"}</option>
            {employees.data?.map(entry => <option key={entry.id} value={entry.id} disabled={!get(entry, "department.id")}>{String(entry.employee_number)} · {String(entry.name)}</option>)}
          </select></label>
          <label>Departemen pemohon<input readOnly value={String(get(employee, "department.name") ?? "")} placeholder="Mengikuti karyawan yang dipilih"/></label>
        </div>
        <div className="line-editor-heading"><h3>Rincian barang</h3>
          <Button type="button" variant="secondary" disabled={lines.length >= 100} onClick={() => setLines(current => [...current, emptyLine()])}><Icon name="plus" size={17}/> Tambah Barang</Button>
        </div>
        <div className="line-editors">{resolvedLines.map((line, index) => {
          const selectedRate = rates.find(rate => String(rate.id) === line.taxRateId) ?? line.taxOption;
          const unavailable = !!line.taxRateId && !taxes.loading && !!taxes.data && !rates.some(rate => String(rate.id) === line.taxRateId);
          return <fieldset className="line-editor" key={line.id}>
            <legend>Barang {index + 1}</legend>
            <PoAutocomplete label="Barang" ariaLabel={"Barang " + (index + 1)} kind="items" value={line.itemName} disabled={busy}
              onChange={name => updateLine(line.id, { itemId: "", itemName: name, stockUnit: undefined,
                description: !line.description || line.description === line.itemName ? name : line.description })}
              onSelect={option => selectItem(line, option)}/>
            {!line.itemId && line.itemName.trim() && canManageItems && <label>Kategori barang baru
              <select aria-label={"Kategori barang baru " + (index + 1)} value={line.categoryId} disabled={categories.loading}
                onChange={event => updateLine(line.id, { categoryId: event.target.value })}>
                <option value="">{categories.loading ? "Memuat kategori..." : "Pilih kategori untuk barang baru"}</option>
                {categories.data?.map(category => <option key={category.id} value={category.id}>{String(category.name)}</option>)}
              </select>
            </label>}
            <label>Deskripsi barang<input required maxLength={200} value={line.description} placeholder="Deskripsi barang" onChange={event => updateLine(line.id, { description: event.target.value })}/></label>
            <div className="line-numbers">
              <label>Jumlah<input type="number" required min="0.001" step="0.001" value={line.quantity} onChange={event => updateLine(line.id, { quantity: event.target.value })}/></label>
              <label>Satuan<select aria-label={"Satuan barang " + (index + 1)} required value={line.unit} title={line.itemId ? "Satuan mengikuti stok barang" : undefined} onChange={event => updateLine(line.id, { unit: event.target.value })}>
                {[...new Set([line.unit, ...ITEM_UNITS])].map(unit => <option key={unit} value={unit} disabled={!!line.itemId && !!line.stockUnit && unit !== line.stockUnit}>{unit}</option>)}
              </select></label>
              <label>Harga satuan (Rp)<input type="number" required min="0" step="0.01" value={line.unitPrice} onChange={event => updateLine(line.id, { unitPrice: event.target.value })}/></label>
              <label>Diskon (%)<input type="number" required min="0" max="100" step="0.0001" value={line.discountPercent} onChange={event => updateLine(line.id, { discountPercent: event.target.value })}/></label>
              {taxScope === "line" && <label>Pajak<select aria-label={"Pajak barang " + (index + 1)} value={line.taxRateId} title={selectedRate?.label}
                onChange={event => updateLine(line.id, { taxRateId: event.target.value, taxOption: rates.find(rate => String(rate.id) === event.target.value) })}>
                <option value="">Tanpa pajak</option>
                {taxes.loading && <option disabled>Memuat tarif…</option>}
                {line.taxRateId && !rates.some(rate => String(rate.id) === line.taxRateId) && <option value={line.taxRateId} disabled>{selectedRate?.label || "Tarif lama"} (tersimpan)</option>}
                {rates.map(rate => <option key={rate.id} value={rate.id}>{rate.label} · {percent.format(Number(rate.rate_percent))}%{rate.tax_type.direction === "withholding" ? " (potongan)" : ""}</option>)}
              </select></label>}
            </div>
            {taxScope === "line" && unavailable && <p className="po-tax-warning">Pilih ulang tarif pajak untuk tanggal pemesanan ini.</p>}
            {lines.length > 1 && <button type="button" className="remove-line" onClick={() => setLines(current => current.filter(entry => entry.id !== line.id))}>Hapus barang {index + 1}</button>}
          </fieldset>;
        })}</div>
        {taxScope === "order" && <div><label>Pajak seluruh PO<select aria-label="Pajak seluruh PO" value="" onChange={event => { if (event.target.value) setOrderRates(current => [...current, event.target.value]); }}>
          <option value="">Pilih tarif pajak</option>{rates.filter(rate => !orderRateIds.includes(String(rate.id))).map(rate => <option key={rate.id} value={rate.id}>{rate.label} · {percent.format(Number(rate.rate_percent))}%</option>)}
        </select></label>
          <ul className="editor-tax-list">{orderRateIds.map(id => <li key={id}><span>{chosenOrderRates.find(rate => String(rate.id) === id)?.label ?? "Tarif #" + id}</span>
            <button type="button" aria-label={"Hapus tarif " + id} onClick={() => setOrderRates(current => current.filter(rate => rate !== id))}><Icon name="close" size={13}/></button></li>)}</ul>
        </div>}
        {taxes.loading && <p className="po-tax-note" role="status">Memuat tarif pajak untuk tanggal pemesanan…</p>}
        {taxes.error && <p className="po-tax-warning" role="alert">{taxes.error}</p>}
        <label>Catatan<textarea rows={3} maxLength={5000} placeholder="Opsional" value={notes} onChange={event => setNotes(event.target.value)}/></label>
        <div className="editor-totals" aria-live="polite">
          <p>Subtotal<strong>{documentMoney(totals.subtotal)}</strong></p><p>Diskon<strong>− {documentMoney(totals.discount)}</strong></p>
          <p>Pajak<strong>{documentMoney(totals.tax)}</strong></p><p className="form-total">Total<strong>{documentMoney(totals.total)}</strong></p>
        </div>
      </div>
      <footer className="po-form-footer">
        {(error || lookupError) && <p className="form-error" role="alert">{error || lookupError}</p>}
        <div className="modal-actions"><Button type="button" variant="secondary" disabled={busy} onClick={onClose}>Batal</Button>
          <Button type="submit" disabled={busy || !companiesReady || masterLoading || taxPending}>{busy ? "Menyimpan..." : masterLoading || taxPending ? "Memuat pilihan..." : row ? "Simpan Perubahan" : "Simpan Draft"}</Button></div>
      </footer>
    </form>
  </Modal>;
}
