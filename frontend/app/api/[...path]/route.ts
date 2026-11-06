import { NextRequest, NextResponse } from "next/server";
import { backendRequest, sessionCookie } from "@/lib/backend";

type LoginData = { token: string; expires_at: string; user: unknown };
type Context = { params: Promise<{ path: string[] }> };

function json(data: unknown, status = 200) {
  return NextResponse.json(data, { status, headers: { "Cache-Control": "no-store" } });
}

async function forward(request: NextRequest, context: Context) {
  const segments = (await context.params).path;
  if (segments.some(segment => !/^[a-z0-9-]+$/.test(segment))) {
    return json({ message: "Endpoint tidak ditemukan." }, 404);
  }

  const path = segments.join("/");
  const method = request.method;
  const login = path === "auth/login" && method === "POST";
  const logout = path === "auth/logout" && method === "POST";
  const read = method === "GET" || method === "HEAD";
  const host = request.headers.get("host") ?? request.nextUrl.host;
  const origin = process.env.APP_ORIGIN || `${request.nextUrl.protocol}//${host.toLowerCase()}`;
  const token = request.cookies.get(sessionCookie)?.value;

  if (!read && request.headers.get("origin") !== origin) {
    return json({ message: "Origin permintaan tidak diizinkan." }, 403);
  }
  if (!login && !(path === "health" && read) && !token) {
    return json({ message: "Silakan masuk kembali." }, 401);
  }
  if (path === "auth/login" && !login) {
    return json({ message: "Metode tidak diizinkan." }, 405);
  }

  const headers = new Headers();
  const idempotencyKey = request.headers.get("idempotency-key");
  if (idempotencyKey) headers.set("Idempotency-Key", idempotencyKey);

  try {
    const body = read ? null : await request.arrayBuffer();
    if (body?.byteLength) {
      const contentType = request.headers.get("content-type")?.split(";")[0].trim().toLowerCase();
      const upload = method === "POST" && /^documents(?:\/[1-9][0-9]*)?$/.test(path) && contentType === "multipart/form-data";
      if (body.byteLength > (upload ? 6 : 4) * 1024 * 1024) return json({ message: "Ukuran permintaan terlalu besar." }, 413);
      if (contentType !== "application/json" && !upload) return json({ message: "Format permintaan tidak didukung." }, 415);
      headers.set("Content-Type", upload ? request.headers.get("content-type")! : "application/json");
    }
    const upstream = await backendRequest(`${path}${request.nextUrl.search}`, {
      method,
      headers,
      body: body?.byteLength ? body : undefined,
    }, login ? undefined : token);

    if (method === "HEAD" || upstream.status === 204) {
      return new NextResponse(null, { status: upstream.status, headers: { "Cache-Control": "no-store" } });
    }
    if (!upstream.headers.get("content-type")?.includes("application/json")) {
      if (upstream.ok && method === "GET" && /^documents\/[1-9][0-9]*\/download$/.test(path)) {
        return new NextResponse(upstream.body, { status: upstream.status, headers: {
          "Content-Type": upstream.headers.get("content-type") || "application/octet-stream",
          "Content-Disposition": upstream.headers.get("content-disposition") || "attachment",
          "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff",
        } });
      }
      return json({ message: "Respons Laravel tidak valid." }, 502);
    }

    const data = await upstream.json();
    if (login && upstream.ok) {
      const session: LoginData | undefined = data?.data;
      const expires = new Date(session?.expires_at ?? "");
      if (!session?.token || !session.user || !Number.isFinite(expires.getTime()) || expires <= new Date()) {
        return json({ message: "Laravel tidak mengembalikan sesi yang valid." }, 502);
      }
      const response = json({ data: { user: session.user } }, upstream.status);
      response.cookies.set(sessionCookie, session.token, {
        httpOnly: true,
        sameSite: "lax",
        secure: origin.startsWith("https://"),
        expires,
        path: "/",
      });
      return response;
    }

    if (upstream.status >= 500) return json({ message: "Laravel gagal memproses permintaan." }, 502);
    if (logout && (upstream.ok || [401, 403].includes(upstream.status))) {
      const response = json({ data: null });
      response.cookies.delete(sessionCookie);
      return response;
    }
    const response = json(data, upstream.status);
    if (upstream.status === 401 && !login) response.cookies.delete(sessionCookie);
    return response;
  } catch {
    return json({ message: "Tidak dapat terhubung ke API Laravel. Coba lagi." }, 502);
  }
}

export const GET = forward;
export const POST = forward;
export const PATCH = forward;
export const PUT = forward;
export const DELETE = forward;
