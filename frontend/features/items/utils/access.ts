import type { ApiUser } from "@/lib/api";

export function canCreateStockItem(user: ApiUser): boolean {
  return (user.roles.includes("super_admin") && user.permissions.includes("items.manage"))
    || (user.roles.includes("staff_hr") && user.permissions.includes("items.create"));
}
