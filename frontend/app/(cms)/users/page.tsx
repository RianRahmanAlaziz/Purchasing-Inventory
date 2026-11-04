import { Suspense } from "react";
import { UsersView } from "@/features/users/views/UsersView";
export default function Page() { return <Suspense fallback={<p className="loading-state">Memuat data...</p>}><UsersView/></Suspense>; }
