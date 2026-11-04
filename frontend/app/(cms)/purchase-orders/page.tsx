import { Suspense } from "react";
import { PurchaseOrdersView } from "@/features/purchase-orders/views/PurchaseOrdersView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><PurchaseOrdersView/></Suspense>; }
