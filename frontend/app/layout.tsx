import type { Metadata } from "next";
import "@flaticon/flaticon-uicons/css/regular/rounded.css";
import "@flaticon/flaticon-uicons/css/solid/rounded.css";
import "./globals.css";
import "./theme.css";
export const metadata: Metadata = { title: "RIS Purchasing", description: "Kelola pengadaan, supplier, dan persediaan dalam satu tempat." };
export default function RootLayout({ children }: { children: React.ReactNode }) { return <html lang="id"><body>{children}</body></html>; }
