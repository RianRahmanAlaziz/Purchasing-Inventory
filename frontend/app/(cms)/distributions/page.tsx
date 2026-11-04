import { Suspense } from "react";
import { DistributionsView } from "@/features/distributions/views/DistributionsView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><DistributionsView/></Suspense>; }
