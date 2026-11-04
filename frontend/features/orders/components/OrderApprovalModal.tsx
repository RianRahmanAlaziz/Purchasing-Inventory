"use client";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { PurchaseOrderLetter } from "@/features/purchase-orders/components/OrderPreview";
import { useOrders } from "@/features/purchase-orders/hooks/useOrders";
import type { PurchaseOrder } from "@/features/purchase-orders/types";

export function OrderApprovalModal({ order, onClose }: { order: PurchaseOrder; onClose: () => void }) {
  const { ready, error, decideOrder } = useOrders();
  const pending = order.status === "Pending Approval";
  return <Modal title={`Persetujuan PO - ${order.id}`} className="order-approval-modal" onClose={onClose}>
    <div className="order-approval-body">
      <div className="order-approval-summary"><strong>{order.id}</strong><Badge tone={pending ? "amber" : order.status === "Rejected" ? "red" : "green"}>{order.status}</Badge></div>
      {order.submittedAt && <p className="form-hint">Diajukan pada {new Date(order.submittedAt).toLocaleString("id-ID")}</p>}
      <div className="po-paper-scroll"><PurchaseOrderLetter order={order} paginate={false}/></div>
      {(!order.company || !order.lines?.length) && <p className="form-hint">Data PO lama belum memuat identitas PT atau rincian barang lengkap. Periksa kelengkapan pesanan sebelum mengambil keputusan.</p>}
      {!pending && (order.approval ? <div className="detail-grid">{order.approval.reviewer && <p>Pemeriksa<strong>{order.approval.reviewer}</strong></p>}<p>Waktu keputusan<strong>{new Date(order.approval.decidedAt).toLocaleString("id-ID")}</strong></p>{order.approval.reason && <p>Catatan / alasan<strong>{order.approval.reason}</strong></p>}</div> : <p className="form-hint">Riwayat keputusan belum tersedia untuk data PO lama ini.</p>)}
    </div>
    <footer className="order-approval-footer">
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="approval-footer-content"><p>{pending ? "Keputusan akan memperbarui status Purchase Order." : "Keputusan PO ini sudah dicatat."}</p><div className="modal-actions"><Button variant="secondary" onClick={onClose}>Tutup</Button>{pending && <><Button className="danger" disabled={!ready} onClick={() => decideOrder(order.id, "Rejected")}>Tidak Setuju</Button><Button disabled={!ready} onClick={() => decideOrder(order.id, "Approved")}>Setuju</Button></>}</div></div>
    </footer>
  </Modal>;
}
