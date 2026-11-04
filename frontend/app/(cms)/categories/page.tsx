import { Suspense } from "react";
import { CategoriesView } from "@/features/categories/views/CategoriesView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><CategoriesView/></Suspense>; }
