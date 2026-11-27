import { Suspense } from "react";
import { ModuleView } from "@/features/modules/views/ModuleView";

export default function Page() {
  return <Suspense fallback={<p className="loading-state">Memuat transfer...</p>}><ModuleView slug="inventory-transfers"/></Suspense>;
}
