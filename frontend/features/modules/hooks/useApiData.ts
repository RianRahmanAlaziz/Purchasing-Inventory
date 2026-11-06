"use client";

import { useEffect, useState } from "react";
import { api, apiList, type ApiResult } from "@/lib/api";

type Result<T> = { path: string; revision: number; data: T | null; error: string; status?: number; lastPage?: number };

export function useApiData<T>(path: string | null, revision = 0, list = false) {
  const [result, setResult] = useState<Result<T> | null>(null);
  useEffect(() => {
    if (!path) return;
    const controller = new AbortController();
    const load: Promise<ApiResult<T>> = list
      ? apiList(path, controller.signal).then(data => ({ data: data as T }))
      : api<T>(path, { signal: controller.signal });

    load.then(
      ({ data, meta }) => {
        if (!controller.signal.aborted) setResult({ path, revision, data, error: "", lastPage: meta?.pagination?.last_page ?? meta?.last_page });
      },
      cause => { if (!controller.signal.aborted) setResult({
        path, revision, data: null,
        error: cause instanceof Error ? cause.message : "Data gagal dimuat.",
        status: cause?.status,
      }); },
    );
    return () => controller.abort();
  }, [path, revision, list]);

  const current = result?.path === path ? result : null;
  const loading = !!path && current?.revision !== revision;
  return { data: current?.data ?? null, error: loading ? "" : current?.error ?? "", status: current?.status, lastPage: current?.lastPage, loading };
}
