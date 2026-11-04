import test from "node:test";
import assert from "node:assert/strict";
import { saveUser, deleteUser, readUsers } from "../utils/users.ts";
import { workspaceToday } from "../../../lib/export-period.ts";

const user = { id: "USR-001", name: "Andi", email: "andi@example.com", role: "Admin", status: "Aktif" };

test("tanggal tambah dicatat sekali, edit tidak mengganti tanggal lama atau menebak tanggal legacy", () => {
  const created = saveUser([], user, false)[0];
  assert.equal(created.createdAt, workspaceToday());
  const original = { ...user, createdAt: "2026-01-01" };
  assert.equal(saveUser([original], { ...original, createdAt: "2026-11-04", name: "Updated" }, true)[0].createdAt, "2026-01-01");
  assert.equal(saveUser([user], user, true)[0].createdAt, undefined);
  assert.deepEqual(readUsers(JSON.stringify([user])), [user]);
});

test("tambah, baca ulang, edit, dan hapus pengguna tanpa mengubah data lain", () => {
  const initial = [user];
  const created = saveUser(initial, { ...user, id: "USR-002", name: " Budi ", email: " BUDI@example.com ", role: "Employee" }, false);
  assert.equal(initial.length, 1);
  assert.equal(created[1].name, "Budi");
  assert.equal(created[1].email, "budi@example.com");
  const restored = readUsers(JSON.stringify(created));
  assert.deepEqual(restored, created);
  const edited = saveUser(restored, { ...restored[1], name: "Budi Baru", role: "Finance", status: "Nonaktif" }, true);
  assert.equal(edited[0], restored[0]);
  assert.equal(edited[1].id, "USR-002");
  assert.equal(edited[1].status, "Nonaktif");
  assert.equal(restored[1].status, "Aktif");
  assert.deepEqual(deleteUser(edited, "USR-002"), [user]);
  assert.deepEqual(readUsers(JSON.stringify(deleteUser([user], user.id))), []);
});

test("email unik berlaku saat tambah dan edit, dengan mengabaikan huruf kapital", () => {
  assert.throws(() => saveUser([user], { ...user, id: "USR-002", email: "ANDI@example.com" }, false), /Email sudah digunakan/);
  const other = { ...user, id: "USR-002", email: "budi@example.com" };
  assert.throws(() => saveUser([user, other], { ...other, email: "andi@example.com" }, true), /Email sudah digunakan/);
  assert.equal(saveUser([user], user, true).length, 1);
});

test("isian tidak valid dan operasi pada ID hilang ditolak", () => {
  for (const change of [{ name: "   " }, { email: "invalid" }, { role: "Unknown" }, { status: "Unknown" }]) {
    assert.throws(() => saveUser([], { ...user, ...change }, false), /Isi nama/);
  }
  assert.throws(() => saveUser([user], user, false), /ID pengguna/);
  assert.throws(() => saveUser([], user, true), /tidak ditemukan/);
  assert.throws(() => deleteUser([], user.id), /tidak ditemukan/);
  for (const raw of ["null", "{}", "invalid", JSON.stringify([user, user]), JSON.stringify([{ ...user, role: "Unknown" }])]) {
    assert.throws(() => readUsers(raw));
  }
});
