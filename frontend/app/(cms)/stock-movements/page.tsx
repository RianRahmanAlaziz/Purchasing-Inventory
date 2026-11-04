import { Suspense } from "react";
import { StockMovementsView } from "@/features/stock-movements/views/StockMovementsView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><StockMovementsView/></Suspense>; }
