import type { Company } from "@/features/companies/types";

export type OrderLine = {
  id: string;
  description: string;
  quantity: number;
  unit: string;
  unitPrice: number;
  discountPercent: number;
  taxPercent: number;
};
export type PurchaseOrder = {
  id: string; supplier: string; initials: string; color: string; date: string;
  amount: number; items: number; status: string;
  itemName?: string; quantity?: number; unitPrice?: number;
  companyId?: string; company?: Company; lines?: OrderLine[];
  expectedDate?: string; paymentTerms?: string; supplierAddress?: string; notes?: string;
  submittedAt?: string;
  approval?: { decision: "Approved" | "Rejected"; reviewer: string; decidedAt: string; reason: string };
};
