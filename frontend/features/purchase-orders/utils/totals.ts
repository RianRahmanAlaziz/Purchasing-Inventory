import type { OrderLine } from "../types/index.ts";
const round = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100;
export function lineTotals(line: OrderLine) {
  const gross = round(line.quantity * line.unitPrice);
  const discount = round(gross * line.discountPercent / 100);
  const net = round(gross - discount);
  const taxableBase = round(net * (line.taxRate?.baseFactor ?? 1));
  const taxAmount = round(taxableBase * line.taxPercent / 100);
  const tax = line.taxRate?.direction === "withholding" ? -taxAmount : taxAmount;
  return { gross, discount, net, tax, total: round(net + tax) };
}
export function orderTotals(lines: OrderLine[]) {
  return lines.reduce((sum, line) => {
    const amount = lineTotals(line);
    return { subtotal: round(sum.subtotal + amount.gross), discount: round(sum.discount + amount.discount), tax: round(sum.tax + amount.tax), total: round(sum.total + amount.total) };
  }, { subtotal: 0, discount: 0, tax: 0, total: 0 });
}
const currency = new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 2 });
export function documentMoney(value: number | string) {
  return currency.format(value as number | `${number}`);
}
