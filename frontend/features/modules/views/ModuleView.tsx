import { ApiModuleView } from "./ApiModuleView";
import { DataModuleView } from "./DataModuleView";
import { apiModules } from "../constants/apiModules";

export function ModuleView({ slug }: { slug: string }) {
  return apiModules[slug] ? <ApiModuleView key={slug} slug={slug}/> : <DataModuleView key={slug} slug={slug}/>;
}
