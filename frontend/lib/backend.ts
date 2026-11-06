export const sessionCookie = "ris_session";

export function backendRequest(path: string, options: RequestInit = {}, token?: string) {
  const origin = process.env.LARAVEL_API_URL || "http://127.0.0.1:8000";
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  if (token) headers.set("Authorization", `Bearer ${token}`);

  return fetch(`${origin.replace(/\/+$/, "")}/api/v1/${path}`, {
    ...options,
    headers,
    cache: "no-store",
    redirect: "error",
    signal: AbortSignal.timeout(15_000),
  });
}
