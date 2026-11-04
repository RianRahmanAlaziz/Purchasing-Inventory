export function paginationRange(total: number, requestedPage: number, pageSize: number) {
  const count = Math.max(0, Math.floor(total));
  const size = Math.max(1, Math.floor(pageSize));
  const pageCount = Math.max(1, Math.ceil(count / size));
  const page = Math.min(pageCount, Math.max(1, Math.floor(requestedPage)));
  const offset = (page - 1) * size;
  return { page, pageCount, offset, start: count ? offset + 1 : 0, end: Math.min(offset + size, count) };
}

export function paginationPages(page: number, pageCount: number): (number | "ellipsis")[] {
  const visible = new Set([1, pageCount, page - 1, page, page + 1]);
  const pages = [...visible].filter(value => value >= 1 && value <= pageCount).sort((a, b) => a - b);
  const result: (number | "ellipsis")[] = [];
  for (let index = 0; index < pages.length; index++) {
    const previous = pages[index - 1];
    if (index && pages[index] - previous === 2) result.push(previous + 1);
    else if (index && pages[index] - previous > 2) result.push("ellipsis");
    result.push(pages[index]);
  }
  return result;
}
