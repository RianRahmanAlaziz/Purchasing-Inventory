import { validDate, workspaceToday } from "../../../lib/export-period.ts";

export const roles = ["Admin", "Purchasing", "Approver / Manager", "Finance", "Warehouse", "Employee"] as const;
export const statuses = ["Aktif", "Nonaktif"] as const;
export type User = { id: string; name: string; email: string; role: typeof roles[number]; status: typeof statuses[number]; createdAt?: string };

export function validUser(value: unknown): value is User {
  if (!value || typeof value !== "object") return false;
  const user = value as User;
  return typeof user.id === "string" && !!user.id.trim()
    && typeof user.name === "string" && !!user.name.trim() && user.name.length <= 120
    && typeof user.email === "string" && user.email.length <= 120 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(user.email)
    && roles.includes(user.role) && statuses.includes(user.status)
    && (user.createdAt === undefined || (typeof user.createdAt === "string" && validDate(user.createdAt)));
}

export function readUsers(raw: string): User[] {
  const users: unknown = JSON.parse(raw);
  if (!Array.isArray(users) || !users.every(validUser)
    || new Set(users.map(user => user.id)).size !== users.length
    || new Set(users.map(user => user.email.toLowerCase())).size !== users.length) throw new Error("Data pengguna tersimpan tidak valid.");
  return users;
}

export function saveUser(users: User[], input: User, editing: boolean): User[] {
  const user = { ...input, name: input.name.trim(), email: input.email.trim().toLowerCase() };
  if (!validUser(user)) throw new Error("Isi nama, email yang valid, peran, dan status pengguna.");
  const exists = users.some(current => current.id === user.id);
  if (editing && !exists) throw new Error("Pengguna tidak ditemukan.");
  if (!editing && exists) throw new Error("ID pengguna sudah digunakan.");
  if (users.some(current => current.id !== user.id && current.email.toLowerCase() === user.email)) throw new Error("Email sudah digunakan oleh pengguna lain.");
  return editing ? users.map(current => current.id === user.id ? { ...user, createdAt: current.createdAt } : current) : [...users, { ...user, createdAt: workspaceToday() }];
}

export function deleteUser(users: User[], id: string): User[] {
  if (!users.some(user => user.id === id)) throw new Error("Pengguna tidak ditemukan.");
  return users.filter(user => user.id !== id);
}
