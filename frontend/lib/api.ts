export type ApiUser = {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  employee_id: number | null;
  default_company_id?: number | null;
};

export class ApiError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
  }
}

export type ApiResult<T> = { data: T; meta?: { pagination?: { last_page: number }; last_page?: number } };
type ApiOptions = Omit<RequestInit, "body"> & { body?: unknown };

export async function api<T>(path: string, { body, ...options }: ApiOptions = {}): Promise<ApiResult<T>> {
  const headers = new Headers(options.headers);
  const multipart = body instanceof FormData;
  if (body !== undefined && !multipart) headers.set("Content-Type", "application/json");
  const response = await fetch(`/api/${path}`, { ...options, headers, body: multipart ? body : JSON.stringify(body) });
  if (response.status === 204) return { data: null as T };
  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    if (response.status === 401 && path !== "auth/login") window.location.replace("/login");
    const fields = Object.values(payload?.errors ?? {}).flat().filter(value => typeof value === "string");
    const message = fields.length ? fields.join(" ") : payload?.message || `Permintaan gagal (${response.status}).`;
    throw new ApiError(message, response.status);
  }
  return payload as ApiResult<T>;
}

export async function apiList<T>(path: string, signal?: AbortSignal): Promise<T[]> {
  const [resource, query] = path.split("?", 2);
  const params = new URLSearchParams(query);
  params.set("per_page", "100");
  const rows: T[] = [];
  let lastPage = 1;
  for (let page = 1; page <= lastPage; page++) {
    params.set("page", String(page));
    const result = await api<T[]>(`${resource}?${params}`, { signal });
    rows.push(...result.data);
    lastPage = result.meta?.pagination?.last_page ?? 1;
  }
  return rows;
}
