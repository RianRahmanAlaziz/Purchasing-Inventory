import test from "node:test";
import assert from "node:assert/strict";
import { paginationPages, paginationRange } from "../pagination.ts";

test("page pertama dan terakhir menampilkan rentang yang tepat", () => {
  assert.deepEqual(paginationRange(23, 1, 10), { page: 1, pageCount: 3, offset: 0, start: 1, end: 10 });
  assert.deepEqual(paginationRange(23, 3, 10), { page: 3, pageCount: 3, offset: 20, start: 21, end: 23 });
});
test("hasil filter atau hapus membatasi halaman ke rentang data", () => {
  assert.equal(paginationRange(11, 5, 5).page, 3);
  assert.equal(paginationRange(2, 3, 10).page, 1);
  assert.equal(paginationRange(10, -1, 5).page, 1);
});
test("data kosong menampilkan nol data dengan satu halaman", () => {
  assert.deepEqual(paginationRange(0, 8, 10), { page: 1, pageCount: 1, offset: 0, start: 0, end: 0 });
});
test("ukuran halaman mengubah jumlah halaman tanpa kehilangan data", () => {
  const rows = Array.from({ length: 51 }, (_, index) => index + 1);
  for (const size of [5,10,25,50]) {
    const visited = [];
    const { pageCount } = paginationRange(rows.length, 1, size);
    for (let page = 1; page <= pageCount; page++) {
      const { offset } = paginationRange(rows.length, page, size);
      visited.push(...rows.slice(offset, offset + size));
    }
    assert.deepEqual(visited, rows);
  }
});
test("nomor halaman panjang diringkas dengan halaman pertama dan terakhir", () => {
  assert.deepEqual(paginationPages(6, 12), [1, "ellipsis", 5, 6, 7, "ellipsis", 12]);
  assert.deepEqual(paginationPages(1, 1), [1]);
  assert.deepEqual(paginationPages(2, 4), [1,2,3,4]);
  assert.deepEqual(paginationPages(1, 12), [1,2,"ellipsis",12]);
});
