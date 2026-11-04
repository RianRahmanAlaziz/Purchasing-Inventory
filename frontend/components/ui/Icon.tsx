const paths: Record<string, string> = {
  eye: "M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z",
  logout: "M9 4H4v16h5 M10 12h11 M17 8l4 4-4 4",
  edit: "m15 5 4 4 M4 20l4-1L20 7a2.8 2.8 0 0 0-4-4L4 15l-1 6 6-1 M13 21h8",
  trash: "M3 6h18 M9 6V3h6v3 M5 6l1 14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1l1-14 M10 10v7 M14 10v7",
  grid: "M5 3h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z M16 3h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z M5 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2Z M16 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-3a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2Z",
  box: "m12 3 9 5-9 5-9-5 9-5Z M3 8v9l9 5 9-5V8 M12 13v9 M7 5.8l9 5",
  cart: "M3 3h2l2.6 12.2a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 2-1.6L22 7H6 M10 21h.01 M18 21h.01 M10 11h7",
  file: "M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z M14 3v6h6 M8 13h8 M8 17h5",
  truck: "M1 5h14v12H1z M15 9h4l4 4v4h-8 M5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4 M19 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4",
  wallet: "M20 8V5a1 1 0 0 0-1-1H5a3 3 0 0 0-3 3v11a2 2 0 0 0 2 2h15a2 2 0 0 0 2-2V9a1 1 0 0 0-1-1H5a1 1 0 0 1 0-2 M21 12h-4a2 2 0 0 0 0 4h4 M17 14h.01",
  users: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M17 4a4 4 0 0 1 0 7 M22 21v-2a4 4 0 0 0-3-3.87",
  category: "m12 3 5 8H7l5-8Z M3 15h7v7H3z M18 22a4 4 0 1 0 0-8 4 4 0 0 0 0 8",
  arrows: "M3 7h17l-4-4 M21 17H4l4 4 M20 7l-4 4 M4 17l4-4",
  search: "M21 21l-5-5 M10.5 18a7.5 7.5 0 1 0 0-15 7.5 7.5 0 0 0 0 15",
  bell: "M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4",
  chevron: "m9 5 7 7-7 7", down: "m6 9 6 6 6-6", plus: "M12 5v14 M5 12h14",
  arrow: "M5 12h14 m-5-5 5 5-5 5", trend: "m3 16 6-6 4 4 8-9 M15 5h6v6",
  download: "M12 3v12 m-5-5 5 5 5-5 M4 16v5h16v-5", calendar: "M3 5h18v16H3z M7 3v4 M17 3v4 M3 10h18",
  clock: "M12 8v5l3 2 M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20", check: "m5 12 4 4L19 6",
  alert: "m12 3 10 18H2L12 3Z M12 9v5 M12 17h.01", help: "M9 9a3 3 0 0 1 6 0c0 2-3 2-3 5 M12 17h.01 M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20",
  settings: "M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1 1-3Z",
  menu: "M3 6h18 M3 12h18 M3 18h18", close: "m6 6 12 12 M6 18 18 6",
};
const fills: Record<string, string> = {
  grid: "M3 5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z M14 16a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-3a2 2 0 0 1-2-2Z",
  box: "m12 3 9 5-9 5-9-5Z M12 13l9-5v9l-9 5Z",
  cart: "M6 7h16l-1.6 8.2a2 2 0 0 1-2 1.6H9.6a2 2 0 0 1-2-1.6Z",
  file: "M6 3h8v6h6v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z",
  wallet: "M4 8h16v12H4a2 2 0 0 1-2-2V7Z",
  truck: "M1 5h14v12H1Z M15 9h4l4 4v4h-8Z",
  clock: "M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Z",
  bell: "M6 8a6 6 0 0 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9Z",
  users: "M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2Z",
  category: "m12 3 5 8H7Z M3 15h7v7H3Z",
  calendar: "M3 5h18v5H3Z",
  alert: "m12 3 10 18H2Z",
};
export function Icon({ name, size = 20 }: { name: string; size?: number }) {
  const iconSize = size < 32 ? size + 2 : size;
  return <svg className={`ui-icon ui-icon-${name}`} width={iconSize} height={iconSize} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{fills[name] && <path d={fills[name]} fill="currentColor" fillOpacity="0.16" stroke="none"/>}<path d={paths[name] || paths.file} /></svg>;
}
