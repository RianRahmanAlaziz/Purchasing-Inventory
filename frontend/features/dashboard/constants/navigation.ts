export const navigation = [
  { title: "UTAMA", items: [{ slug: "dashboard", name: "Dashboard", icon: "grid" }] },
  { title: "PURCHASING", items: [
    { slug: "purchase-orders", name: "Purchase Order", icon: "cart" }, { slug: "orders", name: "Persetujuan PO", icon: "file" },
    { slug: "goods-receipts", name: "Penerimaan Barang", icon: "box" }, { slug: "payments", name: "Pembayaran", icon: "wallet" }, { slug: "receipts", name: "Kuitansi & Dokumen", icon: "file" },
  ] },
  { title: "INVENTORY", items: [
    { slug: "items", name: "Stok Barang", icon: "box" }, { slug: "stock-movements", name: "Mutasi Stok", icon: "arrows" }, { slug: "distributions", name: "Distribusi Barang", icon: "truck" },
  ] },
  { title: "MASTER DATA", items: [
    { slug: "companies", name: "Profil Perusahaan", icon: "box" },
    { slug: "suppliers", name: "Supplier", icon: "users" }, { slug: "categories", name: "Kategori", icon: "category" }, { slug: "users", name: "Pengguna", icon: "users" },
  ] },
];
