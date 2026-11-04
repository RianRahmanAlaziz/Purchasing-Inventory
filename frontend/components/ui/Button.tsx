import type { ButtonHTMLAttributes } from "react";
export function Button({ children, variant = "primary", className = "", ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "secondary" | "ghost" }) { return <button className={`button ${variant} ${className}`} {...props}>{children}</button>; }
