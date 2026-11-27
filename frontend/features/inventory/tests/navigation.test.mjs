import test from "node:test";
import assert from "node:assert/strict";
import { canAccessModule } from "../../dashboard/constants/navigation.ts";

const user = (roles, permissions) => ({ id: 1, name: "Petugas", email: "qa@example.test", roles, permissions, employee_id: null });

test("role saja tidak otomatis membuka transfer atau aset kantor", () => {
  for (const role of ["super_admin", "staff_hr", "staff_hr_ga", "hr", "general_manager"]) {
    assert.equal(canAccessModule(user([role], []), "inventory-transfers"), false);
    assert.equal(canAccessModule(user([role], []), "assets"), false);
  }
});

test("menu transfer dan aset mengikuti permission masing-masing", () => {
  assert.equal(canAccessModule(user(["petugas_kustom"], ["inventory.transfer.view"]), "inventory-transfers"), true);
  assert.equal(canAccessModule(user(["petugas_kustom"], ["inventory.transfer.view"]), "assets"), false);
  assert.equal(canAccessModule(user(["staff_hr_ga"], ["assets.view"]), "assets"), true);
  assert.equal(canAccessModule(user(["staff_hr_ga"], ["assets.view"]), "inventory-transfers"), false);
});

test("permission mutasi saja tidak membuka daftar persediaan yang belum diizinkan", () => {
  assert.equal(canAccessModule(user(["staff_hr_ga"], ["inventory.transfer.send"]), "inventory-transfers"), false);
  assert.equal(canAccessModule(user(["staff_hr_ga"], ["assets.manage"]), "assets"), false);
});
