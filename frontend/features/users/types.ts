import type { ApiUser } from "@/lib/api";

export type Account = Omit<ApiUser, "permissions"> & {
  is_active: boolean;
  created_at: string | null;
};

export type Role = { id: number; name: string; permissions: string[] };

export type Permission = {
  id: number;
  name: string;
  display_name: string | null;
  description: string | null;
};

export type AuditEntry = {
  id: number;
  actor_user_id: number | null;
  event: string;
  subject_type: string;
  subject_id: string;
  before_data: string | null;
  after_data: string | null;
  created_at: string;
};
