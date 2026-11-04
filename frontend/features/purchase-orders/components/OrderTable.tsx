"use client";
import { useState } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { usePagination } from "@/components/ui/hooks/usePagination";
import { rupiah } from "@/lib/utils";
import { useOrders } from "../hooks/useOrders";
import { canDeletePurchaseOrder, canEditPurchaseOrder } from "../utils/mutations";
import { CreateOrderModal } from "./CreateOrderModal";
import { OrderPreview } from "./OrderPreview";
import type { PurchaseOrder } from "../types";

type Selection = { id: string; mode: "view" | "edit" | "delete" | "submit" } | null;
export function OrderTable({ orders, paginationKey = "", initialPageSize = 10 }: { orders: PurchaseOrder[]; paginationKey?: string; initialPageSize?: number }) {
  const { orders: allOrders, deleteOrder, submitOrder, error, clearError, ready } = useOrders();
  const [selection, setSelection] = useState<Selection>(null);
  const pagination = usePagination(orders, paginationKey, initialPageSize);
  const selected = selection ? allOrders.find(order => order.id === selection.id) : undefined;
  function open(order: PurchaseOrder, mode: NonNullable<Selection>["mode"]) { clearError(); setSelection({ id: order.id, mode }); }
  return <>
    <div className="table-scroll"><table><thead><tr><th>No. Purchase Order</th><th>PT Penerbit</th><th>Supplier</th><th>Tanggal</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>{pagination.items.map(order => <tr key={order.id}>
      <td><button className="table-link" onClick={() => open(order, "view")}>{order.id}</button><small>{order.items} item barang</small></td>
      <td className="order-company-cell">{order.company?.name || <span className="legacy-company-label">Belum ditentukan</span>}</td>
      <td><div className="supplier-cell"><span className={`supplier-avatar ${order.color}`}>{order.initials}</span>{order.supplier}</div></td>
      <td>{new Date(`${order.date}T00:00:00`).toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" })}</td>
      <td className="amount">{rupiah(order.amount)}</td>
      <td><Badge tone={order.status === "Pending Approval" ? "amber" : order.status === "Rejected" ? "red" : order.status === "Draft" ? "gray" : order.status === "Sent" ? "blue" : "green"}>{order.status}</Badge></td>
      <td><div className="po-row-actions">
        {order.status === "Draft" && <Button variant="secondary" disabled={!ready} onClick={() => open(order, "submit")}>Ajukan Persetujuan</Button>}
        <button className="icon-button po-action-view" aria-label={`Lihat ${order.id}`} title="Lihat detail PO" onClick={() => open(order, "view")}><Icon name="eye" size={17}/></button>
        <span title={canEditPurchaseOrder(order.status) ? "Edit PO" : "Edit hanya tersedia untuk PO Draft"}><button className="icon-button po-action-edit" aria-label={`Edit ${order.id}`} disabled={!ready || !canEditPurchaseOrder(order.status)} onClick={() => open(order, "edit")}><Icon name="edit" size={17}/></button></span>
        <span title={canDeletePurchaseOrder(order.status) ? "Hapus PO" : "Hapus hanya tersedia untuk PO Draft, Rejected, atau Cancelled"}><button className="icon-button po-action-delete" aria-label={`Hapus ${order.id}`} disabled={!ready || !canDeletePurchaseOrder(order.status)} onClick={() => open(order, "delete")}><Icon name="trash" size={17}/></button></span>
      </div></td>
    </tr>)}</tbody></table>{orders.length === 0 && <div className="empty-state"><Icon name="search" size={32}/><h3>Tidak ada purchase order</h3><p>Coba kata kunci atau filter status lainnya.</p></div>}</div>
    <Pagination {...pagination} label="purchase order"/>
    {selected && selection?.mode === "view" && <OrderPreview order={selected} onClose={() => setSelection(null)}/>}
    {selected && selection?.mode === "edit" && <CreateOrderModal key={selected.id} initialOrder={selected} onClose={() => setSelection(null)}/>}
    {selected && selection?.mode === "submit" && <Modal title="Ajukan Persetujuan PO" onClose={() => setSelection(null)}>
      <p>Ajukan <strong>{selected.id}</strong> untuk diperiksa? Setelah diajukan, PO tidak dapat diedit atau dihapus.</p>
      <p className="form-hint">Pastikan PT penerbit dan rincian barang lengkap. Data PO lama dapat dilengkapi melalui Edit.</p>
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="modal-actions"><Button variant="secondary" onClick={() => setSelection(null)}>Batal</Button><Button disabled={!ready || selected.status !== "Draft"} onClick={() => { if (submitOrder(selected.id)) setSelection(null); }}>Ya, Ajukan Persetujuan</Button></div>
    </Modal>}
    {selected && selection?.mode === "delete" && <Modal title="Hapus Purchase Order?" onClose={() => setSelection(null)}>
      <div className="po-delete-notice"><span><Icon name="trash" size={25}/></span><p>Purchase order <strong>{selected.id}</strong> untuk <strong>{selected.supplier}</strong> akan dihapus dari daftar. Tindakan ini tidak dapat dibatalkan.</p></div>
      <div className="detail-grid"><p>Status<strong>{selected.status}</strong></p><p>Total<strong>{rupiah(selected.amount)}</strong></p></div>
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="modal-actions"><Button variant="secondary" autoFocus onClick={() => setSelection(null)}>Batal</Button><Button className="danger" disabled={!ready || !canDeletePurchaseOrder(selected.status)} onClick={() => { if (deleteOrder(selected.id)) setSelection(null); }}><Icon name="trash" size={16}/> Ya, Hapus PO</Button></div>
    </Modal>}
  </>;
}
