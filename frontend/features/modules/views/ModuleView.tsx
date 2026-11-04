import { DataModuleView } from "./DataModuleView";
import { PurchaseOrdersModuleView } from "@/features/purchase-orders/views/PurchaseOrdersModuleView";

export function ModuleView({ slug }: { slug: string }) {
  return slug === "purchase-orders" ? <PurchaseOrdersModuleView/> : <DataModuleView key={slug} slug={slug}/>;
}