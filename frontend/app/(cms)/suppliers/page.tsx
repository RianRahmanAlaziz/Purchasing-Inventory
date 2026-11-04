import { Suspense } from "react";
import { SuppliersView } from "@/features/suppliers/views/SuppliersView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><SuppliersView/></Suspense>; }
