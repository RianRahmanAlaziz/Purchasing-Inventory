// Ikon dari Flaticon UIcons (https://www.flaticon.com/uicons), paket @flaticon/flaticon-uicons.
// Nama ikon internal aplikasi dipetakan ke nama kelas Flaticon agar pemanggil cukup memakai <Icon name="...">.
const icons: Record<string, string> = {
  // Umum
  eye: "eye", logout: "sign-out-alt", edit: "pencil", trash: "trash", search: "search", bell: "bell",
  chevron: "angle-small-right", down: "angle-small-down", plus: "plus", arrow: "arrow-right", trend: "chart-line-up",
  download: "download", calendar: "calendar", clock: "clock", check: "check", alert: "triangle-warning",
  help: "interrogation", settings: "settings", menu: "menu-burger", close: "cross-small",
  grid: "apps", box: "box-open", cart: "shopping-cart", file: "document", truck: "truck-side", wallet: "wallet",
  users: "users", category: "apps-sort", arrows: "exchange",
  // Menu
  dashboard: "dashboard-monitor", approval: "list-check", order: "receipt", receiving: "box-check",
  invoice: "file-invoice-dollar", payment: "credit-card", "document-signed": "document-signed", stock: "boxes",
  company: "building", supplier: "store-alt", tag: "tags", tax: "badge-percent", percent: "percentage",
  excel: "file-excel", department: "sitemap", employee: "id-badge", "user-gear": "user-gear", shield: "shield-check",
};

export function Icon({ name, size = 20, solid = false }: { name: string; size?: number; solid?: boolean }) {
  const glyph = icons[name] ?? icons.file;
  return <i className={`ui-icon ui-icon-${name} fi ${solid ? "fi-sr-" : "fi-rr-"}${glyph}`}
    style={{ fontSize: size }} aria-hidden="true"/>;
}
