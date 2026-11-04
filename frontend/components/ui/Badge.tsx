export function Badge({ children, tone = "green" }: { children: React.ReactNode; tone?: string }) { return <span className={`badge ${tone}`}><span className="badge-dot" />{children}</span>; }
