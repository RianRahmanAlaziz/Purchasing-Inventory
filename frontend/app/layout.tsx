import type { Metadata } from "next";
import "./globals.css";
import "./theme.css";
export const metadata: Metadata = { title: "RIS Purchasing", description: "Kelola pengadaan, supplier, dan persediaan dalam satu tempat." };
export default function RootLayout({ children }: { children: React.ReactNode }) { return <html lang="id"><body>{children}</body></html>; }
