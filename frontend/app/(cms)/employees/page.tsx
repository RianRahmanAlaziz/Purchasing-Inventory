import { Suspense } from "react";
import { ModuleView } from "@/features/modules/views/ModuleView";

export default function Page() {
  return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><ModuleView slug="employees"/></Suspense>;
}
