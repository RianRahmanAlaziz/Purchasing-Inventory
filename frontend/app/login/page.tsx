import type { Metadata } from "next";
import { LoginView } from "@/features/auth/views/LoginView";

export const metadata: Metadata = { title: "Masuk | RIS Purchasing" };

export default function LoginPage() {
  return <LoginView/>;
}
