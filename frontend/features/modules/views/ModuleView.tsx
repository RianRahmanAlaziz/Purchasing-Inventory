"use client";

import { useSession } from "@/features/auth/Session";
import { canAccessModule } from "@/features/dashboard/constants/navigation";
import { ApiModuleView } from "./ApiModuleView";
import { DataModuleView } from "./DataModuleView";
import { apiModules } from "../constants/apiModules";

export function ModuleView({ slug }: { slug: string }) {
  const user = useSession();
  if (!canAccessModule(user, slug)) return <p className="form-error" role="alert">Anda tidak memiliki akses ke halaman ini.</p>;
  return apiModules[slug] ? <ApiModuleView key={slug} slug={slug}/> : <DataModuleView key={slug} slug={slug}/>;
}
