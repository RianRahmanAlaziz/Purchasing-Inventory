import { Suspense } from "react";
import { OrdersView } from "@/features/orders/views/OrdersView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><OrdersView/></Suspense>; }
