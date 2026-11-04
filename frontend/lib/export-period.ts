export type ExportPeriod = "all" | "week" | "month" | "custom";
export type DateRange = { start: string; end: string };
export type ExportRecord = { cells: (string | number)[]; date?: string };

export function validDate(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value) && Number.isFinite(Date.parse(value)) && new Date(value).toISOString().slice(0, 10) === value;
}

export function workspaceToday(now = new Date()): string {
  const parts = new Intl.DateTimeFormat("en", { timeZone: "Asia/Bangkok", year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(now);
  return ["year", "month", "day"].map(type => parts.find(part => part.type === type)!.value).join("-");
}

export function exportRange(period: ExportPeriod, custom: DateRange, today: string): DateRange | null {
  if (period === "all") return null;
  if (period === "custom") {
    if (!validDate(custom.start) || !validDate(custom.end)) throw new Error("Isi tanggal mulai dan akhir yang valid.");
    if (custom.start > custom.end) throw new Error("Tanggal akhir harus sama atau setelah tanggal mulai.");
    return custom;
  }
  if (!validDate(today)) throw new Error("Tanggal hari ini tidak valid.");
  const start = new Date(`${today}T00:00:00Z`);
  if (period === "week") start.setUTCDate(start.getUTCDate() - 6);
  else {
    const day = start.getUTCDate();
    start.setUTCDate(1);
    start.setUTCMonth(start.getUTCMonth() - 1);
    const lastDay = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 0)).getUTCDate();
    start.setUTCDate(Math.min(day, lastDay));
  }
  return { start: start.toISOString().slice(0, 10), end: today };
}

export function filterExportRecords(records: ExportRecord[], range: DateRange | null): ExportRecord[] {
  if (!range) return records;
  return records.filter(record => record.date && validDate(record.date) && record.date >= range.start && record.date <= range.end);
}
