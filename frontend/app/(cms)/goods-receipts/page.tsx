import { Suspense } from "react";
import { GoodsReceiptsView } from "@/features/goods-receipts/views/GoodsReceiptsView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><GoodsReceiptsView/></Suspense>; }
