import { Suspense } from "react";
import { ReceiptsView } from "@/features/receipts/views/ReceiptsView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><ReceiptsView/></Suspense>; }
