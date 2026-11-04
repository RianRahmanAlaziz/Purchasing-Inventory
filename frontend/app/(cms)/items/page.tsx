import { Suspense } from "react";
import { ItemsView } from "@/features/items/views/ItemsView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><ItemsView/></Suspense>; }
