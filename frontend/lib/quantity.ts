export function quantityInput(value: unknown): string {
  const text = String(value ?? "");
  return /^-?\d+\.\d+$/.test(text) ? text.replace(/0+$/, "").replace(/\.$/, "") : text;
}

export function formatQuantity(value: unknown): string {
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(quantityInput(value).trim());
  if (!match) return "—";
  const integer = match[2].replace(/^0+(?=\d)/, "");
  const fraction = match[3] ?? "";
  const sign = match[1] && (integer !== "0" || fraction) ? "-" : "";
  // Keep database decimals as strings so large stock quantities retain precision.
  return sign + integer.replace(/\B(?=(\d{3})+(?!\d))/g, ".") + (fraction ? "," + fraction : "");
}
