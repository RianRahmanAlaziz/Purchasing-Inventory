export const rupiah = (value: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value);
export function exportCsv(filename: string, rows: (string | number)[][]) {
  const csv = rows.map(row => row.map(value => {
    const cell = String(value);
    const formula = typeof value === "string" && /^(?:\s*[=+@-]|[\t\r\n])/.test(cell)
      && !/^[+-]?\d+(?:\.\d+)?$/.test(cell);
    return `"${formula ? "'" : ""}${cell.replaceAll('"', '""')}"`;
  }).join(",")).join("\r\n");
  const url = URL.createObjectURL(new Blob(["\ufeff", csv], { type: "text/csv;charset=utf-8;" }));
  const anchor = document.createElement("a"); anchor.href = url; anchor.download = filename; anchor.click(); URL.revokeObjectURL(url);
}
