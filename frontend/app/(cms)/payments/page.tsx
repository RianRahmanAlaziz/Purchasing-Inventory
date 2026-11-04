import { Suspense } from "react";
import { PaymentsView } from "@/features/payments/views/PaymentsView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><PaymentsView/></Suspense>; }
