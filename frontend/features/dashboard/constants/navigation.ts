import type { ApiUser } from "@/lib/api";

// `alsoRequires`: izin tambahan agar menu tampil. Dipakai bila izin utama juga dibutuhkan
// modul lain sebagai lookup (mis. items.view untuk pilihan barang di form PO).
type NavigationItem = { slug: string; name: string; icon: string; permission?: string; alsoRequires?: string[]; roles?: string[] };

export const navigation: { title: string; items: NavigationItem[]; masterData?: boolean }[] = [
  { title: "UTAMA", items: [{ slug: "dashboard", name: "Dashboard", icon: "dashboard", permission: "reports.view" }] },
  { title: "PURCHASING", items: [
    { slug: "purchase-orders", name: "Purchase Order", icon: "cart", permission: "po.view", alsoRequires: ["po.create"] },
    { slug: "approvals", name: "Persetujuan PO", icon: "approval", permission: "po.view", roles: ["hr", "general_manager", "super_admin"] },
    { slug: "orders", name: "Order Management", icon: "order", permission: "orders.view" },
    { slug: "goods-receipts", name: "Penerimaan Barang", icon: "receiving", permission: "grn.view" },
    { slug: "invoices", name: "Tagihan Supplier", icon: "invoice", permission: "invoices.view" },
    { slug: "payments", name: "Pembayaran", icon: "payment", permission: "payments.view" },
    { slug: "receipts", name: "Kuitansi & Dokumen", icon: "document-signed", permission: "documents.view" },
  ] },
  { title: "INVENTORY", items: [
    { slug: "items", name: "Stok Barang", icon: "stock", permission: "items.view", alsoRequires: ["inventory.view"] },
    { slug: "stock-movements", name: "Mutasi Stok", icon: "arrows", permission: "inventory.view" },
    { slug: "distributions", name: "Distribusi Barang", icon: "truck", permission: "distribution.view" },
  ] },
  { title: "MASTER DATA", masterData: true, items: [
    { slug: "companies", name: "Profil Perusahaan", icon: "company", permission: "companies.view" },
    { slug: "suppliers", name: "Supplier", icon: "supplier", permission: "suppliers.view" },
    { slug: "categories", name: "Kategori Produk", icon: "tag", permission: "categories.view" },
    { slug: "tax-types", name: "Jenis Pajak", icon: "tax", permission: "taxes.view" },
    { slug: "tax-rates", name: "Tarif Pajak", icon: "percent", permission: "taxes.view" },
    { slug: "departments", name: "Departemen", icon: "department", permission: "departments.view" },
    { slug: "employees", name: "Karyawan", icon: "employee", permission: "employees.view" },
  ] },
  { title: "ADMINISTRASI", items: [
    { slug: "users", name: "Pengguna", icon: "users", permission: "users.view" },
    { slug: "access", name: "Role & Izin", icon: "shield", permission: "roles.manage" },
  ] },
];

export function isSuperAdmin(user: ApiUser) {
  return user.roles.includes("super_admin");
}

export function visibleNavigation(user: ApiUser) {
  const permissions = new Set(user.permissions);
  return navigation.filter(group => !group.masterData || isSuperAdmin(user)).map(group => ({
    ...group,
    items: group.items.filter(item => {
      if (item.slug === "distributions" && user.employee_id !== null) return true;
      if (item.slug === "purchase-orders" && !isSuperAdmin(user)
        && (user.roles.includes("hr") || user.roles.includes("general_manager"))) return false;
      if (item.roles && !item.roles.some(role => user.roles.includes(role))) return false;
      if (item.alsoRequires && !item.alsoRequires.every(permission => permissions.has(permission))) return false;
      return item.permission !== undefined && permissions.has(item.permission);
    }),
  })).filter(group => group.items.length > 0);
}

export function canAccessModule(user: ApiUser, slug: string) {
  return visibleNavigation(user).some(group => group.items.some(item => item.slug === slug));
}

export type PurchaseOrderDestination = { slug: "purchase-orders" | "approvals"; id: string | number };

export function purchaseOrderHref(user: ApiUser, destination: PurchaseOrderDestination) {
  const { slug, id } = destination;
  if (canAccessModule(user, slug)) return `/${slug}?po=${id}`;
  if (slug === "purchase-orders" && canAccessModule(user, "approvals")) return `/approvals?po=${id}`;
  return null;
}

export function workspaceHome(user: ApiUser) {
  const first = visibleNavigation(user).flatMap(group => group.items).find(item => item.permission);
  return first ? "/" + first.slug : "/profile";
}
