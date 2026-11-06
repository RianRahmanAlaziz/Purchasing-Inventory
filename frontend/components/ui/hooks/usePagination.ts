"use client";
import { useState } from "react";
import { paginationRange } from "@/lib/pagination";

export function usePagination<T>(items: T[], resetKey = "", initialPageSize = 10) {
  const [currentPage, setCurrentPage] = useState(1);
  const [pageSize, setSize] = useState(initialPageSize);
  const [previousKey, setPreviousKey] = useState(resetKey);
  if (previousKey !== resetKey) {
    setPreviousKey(resetKey);
    setCurrentPage(1);
  } else {
    const lastPage = paginationRange(items.length, currentPage, pageSize).pageCount;
    if (currentPage > lastPage) setCurrentPage(lastPage);
  }
  const range = paginationRange(items.length, previousKey === resetKey ? currentPage : 1, pageSize);
  return {
    ...range, pageSize, total: items.length,
    items: items.slice(range.offset, range.offset + pageSize),
    setPage: (page: number) => setCurrentPage(paginationRange(items.length, page, pageSize).page),
    setPageSize: (size: number) => { if ([5, 10, 25, 50].includes(size)) { setSize(size); setCurrentPage(1); } },
  };
}
