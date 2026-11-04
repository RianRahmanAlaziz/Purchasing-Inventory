"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { useCompanies } from "@/features/companies/hooks/useCompanies";
import { useOrders } from "../hooks/useOrders";
import { documentMoney, orderTotals } from "../utils/totals";
import type { OrderLine, PurchaseOrder } from "../types";

const newLine = (): OrderLine => ({ id: crypto.randomUUID(), description: "", quantity: 1, unit: "pcs", unitPrice: 0, discountPercent: 0, taxPercent: 0 });
export function CreateOrderModal({ onClose, initialOrder }: { onClose: () => void; initialOrder?: PurchaseOrder }) {
  const { orders, addOrder, updateOrder, clearError, error, ready: ordersReady } = useOrders();
  const { companies, activeId, ready: companiesReady } = useCompanies();
  const [companyId, setCompanyId] = useState(initialOrder ? initialOrder.companyId || "" : activeId);
  const [supplier, setSupplier] = useState(initialOrder?.supplier || "PT Sumber Makmur");
  const [supplierAddress, setSupplierAddress] = useState(initialOrder?.supplierAddress || "");
  const [date, setDate] = useState(() => initialOrder?.date || new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Bangkok" }));
  const [expectedDate, setExpectedDate] = useState(initialOrder?.expectedDate || "");
  const [paymentTerms, setPaymentTerms] = useState(initialOrder?.paymentTerms || "Kontan");
  const [notes, setNotes] = useState(initialOrder?.notes || "");
  const [lines, setLines] = useState<OrderLine[]>(() => initialOrder?.lines?.length ? initialOrder.lines.map(line => ({ ...line })) : initialOrder?.itemName ? [{ ...newLine(), description: initialOrder.itemName, quantity: initialOrder.quantity || 1, unitPrice: initialOrder.unitPrice || 0 }] : [newLine()]);
  const [validation, setValidation] = useState("");
  const totals = orderTotals(lines);
  const companyOptions = initialOrder?.company ? [...companies.filter(current => current.id !== initialOrder.company?.id), initialOrder.company] : companies;
  const company = companyOptions.find(current => current.id === companyId);
  const ready = ordersReady && companiesReady;
  useEffect(() => { clearError(); }, [clearError]);
  function updateLine(id: string, key: keyof OrderLine, value: string | number) { setLines(current => current.map(line => line.id === id ? { ...line, [key]: value } : line)); }

  return <Modal title={initialOrder ? `Edit Purchase Order - ${initialOrder.id}` : "Buat Purchase Order"} className="order-editor-modal" onClose={onClose}><form className="order-form" onSubmit={event => {
    event.preventDefault(); setValidation("");
    if (!ready || !company) { setValidation("Pilih PT penerbit terlebih dahulu."); return; }
    if (!supplier.trim() || lines.some(line => !line.description.trim() || !line.unit.trim() || !Number.isFinite(line.quantity) || line.quantity <= 0 || !Number.isFinite(line.unitPrice) || line.unitPrice <= 0 || !Number.isFinite(line.discountPercent) || line.discountPercent < 0 || line.discountPercent > 100 || !Number.isFinite(line.taxPercent) || line.taxPercent < 0 || line.taxPercent > 100)) { setValidation("Lengkapi supplier, deskripsi, satuan, jumlah, dan harga setiap barang."); return; }
    if (expectedDate && expectedDate < date) { setValidation("Tanggal kedatangan tidak boleh sebelum tanggal pemesanan."); return; }
    const number = Math.max(48, ...orders.map(order => Number(order.id.split("-").at(-1)) || 0)) + 1;
    const nextOrder: PurchaseOrder = { id: initialOrder?.id || `PO-${date.slice(0,4)}-${String(number).padStart(4,"0")}`, supplier: supplier.trim(), supplierAddress: supplierAddress.trim(), initials: supplier.replace(/^(PT|CV) /, "").split(" ").filter(Boolean).map(word => word[0]).slice(0,2).join(""), color: initialOrder?.color || "blue", date, expectedDate, paymentTerms: paymentTerms.trim(), notes: notes.trim(), amount: totals.total, items: lines.length, status: "Draft", companyId: company.id, company: { ...company }, lines: lines.map(line => ({ ...line, description: line.description.trim(), unit: line.unit.trim() })) };
    const saved = initialOrder ? updateOrder(nextOrder) : addOrder(nextOrder);
    if (saved) onClose();
  }}>
    <div className="po-form-body">
    <p className="muted">Pilih PT penerbit dan lengkapi rincian surat pesanan.</p>
    {initialOrder && !initialOrder.lines?.length && !initialOrder.itemName && <p className="form-hint">PO lama belum memiliki rincian barang. Lengkapi PT penerbit dan barang sebelum menyimpan perubahan.</p>}
    <div className="po-company-picker"><label>PT penerbit<select required value={companyId} disabled={!companiesReady} onChange={event => setCompanyId(event.target.value)}><option value="" disabled>Pilih PT penerbit</option>{companyOptions.map(current => <option key={current.id} value={current.id}>{current.name}</option>)}</select></label><Link href="/companies" onClick={onClose} className="text-link">Kelola nama & logo PT <Icon name="arrow" size={14}/></Link></div>
    {company && <div className="selected-company">{company.logo && <img src={company.logo} alt={`Logo ${company.name}`}/>}<div><strong>{company.name}</strong><small>{company.address || "Alamat belum diisi"}</small></div></div>}
    <div className="form-row"><label>Supplier<input required maxLength={120} list="po-supplier-options" value={supplier} onChange={event => setSupplier(event.target.value)}/><datalist id="po-supplier-options">{[...new Set(orders.map(order => order.supplier))].map(name => <option key={name} value={name}/>)}</datalist></label><label>Alamat supplier<input maxLength={300} value={supplierAddress} onChange={event => setSupplierAddress(event.target.value)} placeholder="Opsional"/></label></div>
    <div className="form-row"><label>Tanggal pemesanan<input required type="date" value={date} onChange={event => setDate(event.target.value)}/></label><label>Kedatangan yang diharapkan<input type="date" min={date} value={expectedDate} onChange={event => setExpectedDate(event.target.value)}/></label></div>
    <div className="line-editor-heading"><h3>Rincian barang</h3><Button type="button" variant="secondary" onClick={() => setLines(current => [...current, newLine()])}><Icon name="plus" size={14}/> Tambah Barang</Button></div>
    <div className="line-editors">{lines.map((line, index) => <fieldset className="line-editor" key={line.id}><legend>Barang {index + 1}</legend><label>Deskripsi barang<input required maxLength={200} value={line.description} onChange={event => updateLine(line.id,"description",event.target.value)} placeholder="Contoh: PLASTIK LOCCO 24"/></label><div className="line-numbers"><label>Jumlah<input type="number" required min="0.01" max="1000000" step="0.01" value={line.quantity || ""} onChange={event => updateLine(line.id,"quantity",Number(event.target.value))}/></label><label>Satuan<input required maxLength={20} value={line.unit} onChange={event => updateLine(line.id,"unit",event.target.value)}/></label><label>Harga satuan (Rp)<input type="number" required min="0.01" max="1000000000" step="0.01" value={line.unitPrice || ""} onChange={event => updateLine(line.id,"unitPrice",Number(event.target.value))}/></label><label>Diskon (%)<input type="number" required min="0" max="100" step="0.01" value={line.discountPercent} onChange={event => updateLine(line.id,"discountPercent",Number(event.target.value))}/></label><label>Pajak (%)<input type="number" required min="0" max="100" step="0.01" value={line.taxPercent} onChange={event => updateLine(line.id,"taxPercent",Number(event.target.value))}/></label></div>{lines.length > 1 && <button type="button" className="remove-line" onClick={() => setLines(current => current.filter(item => item.id !== line.id))}>Hapus barang {index + 1}</button>}</fieldset>)}</div>
    <div className="form-row"><label>Termin pembayaran<input required maxLength={100} value={paymentTerms} onChange={event => setPaymentTerms(event.target.value)} placeholder="Kontan / 30 hari"/></label><label>Catatan<input maxLength={500} value={notes} onChange={event => setNotes(event.target.value)} placeholder="Opsional"/></label></div>
    <div className="editor-totals"><p>Subtotal<strong>{documentMoney(totals.subtotal)}</strong></p><p>Diskon<strong>- {documentMoney(totals.discount)}</strong></p><p>Pajak<strong>{documentMoney(totals.tax)}</strong></p><p className="form-total">Total<strong>{documentMoney(totals.total)}</strong></p></div>
    <p className="form-hint">Draft dan identitas PT disimpan di browser ini. Pajak dihitung setelah diskon sesuai persentase yang Anda isi.</p>
    </div>
    <footer className="po-form-footer">
    {(validation || error) && <p className="form-error" role="alert">{validation || error}</p>}
    <div className="modal-actions"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button type="submit" disabled={!ready}>{initialOrder ? "Simpan Perubahan" : "Simpan Draft"}</Button></div>
    </footer>
  </form></Modal>;
}
