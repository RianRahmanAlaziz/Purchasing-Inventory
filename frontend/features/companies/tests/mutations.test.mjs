import test from "node:test";
import assert from "node:assert/strict";
import { removeCompany } from "../utils/mutations.ts";

const first = { id: "pt-1", name: "PT Pertama", address: "", phone: "", email: "", logo: "" };
const second = { ...first, id: "pt-2", name: "PT Kedua" };

test("hapus PT aktif memilih pengganti tanpa mengubah snapshot PO", () => {
  const companies = [first, second];
  const order = { companyId: first.id, company: { ...first } };
  const result = removeCompany(companies, first.id, first.id);
  assert.deepEqual(result, { companies: [second], activeId: second.id });
  assert.equal(companies.length, 2);
  assert.deepEqual(order.company, first);
});

test("hapus PT nonaktif mempertahankan PT aktif, dan hapus terakhir menghasilkan daftar kosong", () => {
  assert.deepEqual(removeCompany([first, second], first.id, second.id), { companies: [first], activeId: first.id });
  const empty = removeCompany([first], first.id, first.id);
  assert.deepEqual(JSON.parse(JSON.stringify(empty)), { companies: [], activeId: "" });
  assert.throws(() => removeCompany([], "", "missing"), /tidak ditemukan/);
});
