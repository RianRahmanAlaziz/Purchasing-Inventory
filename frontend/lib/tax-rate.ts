const percent = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 4 });

export function formatEffectiveTaxRate(rate: unknown, factor: unknown): string {
  const effective = Number(rate) * Number(factor ?? 1);
  return Number.isFinite(effective) ? percent.format(effective) + "%" : "—";
}
