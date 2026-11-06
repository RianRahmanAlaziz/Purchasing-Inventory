import type { ApiUser } from "@/lib/api";

type NavigationItem = { slug: string; name: string; icon: string; permission?: string };

export const navigation: { title: string; items: NavigationItem[] }[] = [
  { title: "UTAMA", items: [{ slug: "dashboard", name: "Dashboard", icon: "grid", permission: "reports.view" }] },
  { title: "PURCHASING", items: [
    { slug: "purchase-orders", name: "Purchase Order", icon: "cart", permission: "po.view" },
    { slug: "approvals", name: "Persetujuan PO", icon: "file", permission: "po.view" },
    { slug: "orders", name: "Order Management", icon: "file", permission: "orders.view" },
    { slug: "goods-receipts", name: "Penerimaan Barang", icon: "box", permission: "grn.view" },
    { slug: "invoices", name: "Tagihan Supplier", icon: "wallet", permission: "invoices.view" },
    { slug: "payments", name: "Pembayaran", icon: "wallet", permission: "payments.view" },
    { slug: "receipts", name: "Kuitansi & Dokumen", icon: "file", permission: "documents.view" },
  ] },
  { title: "INVENTORY", items: [
    { slug: "items", name: "Stok Barang", icon: "box", permission: "items.view" },
    { slug: "stock-movements", name: "Mutasi Stok", icon: "arrows", permission: "inventory.view" },
    { slug: "distributions", name: "Distribusi Barang", icon: "truck", permission: "distribution.view" },
  ] },
  { title: "MASTER DATA", items: [
    { slug: "companies", name: "Profil Perusahaan", icon: "box", permission: "companies.view" },
    { slug: "suppliers", name: "Supplier", icon: "users", permission: "suppliers.view" },
    { slug: "categories", name: "Kategori", icon: "category", permission: "categories.view" },
    { slug: "tax-types", name: "Jenis Pajak", icon: "category", permission: "taxes.view" },
    { slug: "tax-rates", name: "Tarif Pajak", icon: "wallet", permission: "taxes.view" },
    { slug: "departments", name: "Departemen", icon: "grid", permission: "departments.view" },
    { slug: "employees", name: "Karyawan", icon: "users", permission: "employees.view" },
  ] },
  { title: "ADMINISTRASI", items: [
    { slug: "users", name: "Pengguna", icon: "users", permission: "users.view" },
    { slug: "access", name: "Role & Izin", icon: "settings", permission: "roles.manage" },
  ] },
];

export function visibleNavigation(user: ApiUser) {
  const permissions = new Set(user.permissions);
  return navigation.map(group => ({
    ...group,
    items: group.items.filter(item => {
      if (item.slug === "distributions" && user.employee_id !== null) return true;
      return item.permission !== undefined && permissions.has(item.permission);
    }),
  })).filter(group => group.items.length > 0);
}

export function workspaceHome(user: ApiUser) {
  const first = visibleNavigation(user).flatMap(group => group.items).find(item => item.permission);
  return first ? "/" + first.slug : "/profile";
}
