"use client";

import { useEffect, useId, useLayoutEffect, useRef, useState, type KeyboardEvent } from "react";
import { useApiData } from "@/features/modules/hooks/useApiData";
import { formatQuantity } from "@/lib/quantity";

export type PoSuggestion = {
  id: number; name: string; source: "history" | "stock" | "master"; address?: string;
  code?: string; sku?: string; unit?: string; stock_name?: string; stock_quantity?: string;
  unit_price?: string; category_id?: number;
};
type Props = {
  label: string; ariaLabel?: string; value: string; kind: "suppliers" | "items"; disabled?: boolean;
  onChange: (value: string) => void; onSelect: (option: PoSuggestion) => void;
};

function caption(option: PoSuggestion) {
  if (option.source === "master") return "Master supplier" + (option.code ? " · " + option.code : "");
  if (option.source === "history" && !option.sku) return "Riwayat PO" + (option.address ? " · " + option.address.replace(/\s+/g, " ") : "");
  const stock = option.stock_quantity !== undefined
    ? "Stok " + formatQuantity(option.stock_quantity) + " " + (option.unit ?? "") : "";
  return [option.source === "history" ? "Riwayat PO" : "Stok barang", option.sku, stock].filter(Boolean).join(" · ");
}

export function PoAutocomplete({ label, ariaLabel, value, kind, disabled, onChange, onSelect }: Props) {
  const inputId = useId();
  const listId = inputId + "-options";
  const control = useRef<HTMLInputElement>(null);
  const panel = useRef<HTMLDivElement>(null);
  const [focused, setFocused] = useState(false);
  const [settled, setSettled] = useState(value.trim());
  const [selection, setSelection] = useState({ query: value, index: -1 });
  const open = focused && !disabled && value.trim().length > 0;
  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(value.trim()), 220);
    return () => window.clearTimeout(timer);
  }, [value]);
  const waiting = settled !== value.trim();
  const lookup = useApiData<PoSuggestion[]>(open && !waiting ? "purchase-orders/suggestions/" + kind + "?q=" + encodeURIComponent(settled) : null);
  const options = lookup.data ?? [];
  const loading = waiting || lookup.loading;
  const active = selection.query === value && selection.index < options.length ? selection.index : -1;

  useLayoutEffect(() => {
    const list = panel.current;
    const input = control.current;
    if (!open || !list || !input) return;
    if (typeof list.showPopover === "function" && !list.matches(":popover-open")) list.showPopover();
    function place() {
      if (!input || !list) return;
      const rect = input.getBoundingClientRect();
      const width = Math.min(rect.width, window.innerWidth - 16);
      list.style.width = width + "px";
      const below = window.innerHeight - rect.bottom - 8;
      const above = rect.top - 8;
      const desired = Math.min(list.scrollHeight, 256);
      const flip = below < desired && above > below;
      const height = Math.min(256, Math.max(60, flip ? above : below));
      Object.assign(list.style, {
        width: width + "px", left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)) + "px",
        top: (flip ? Math.max(8, rect.top - Math.min(height, desired) - 4) : rect.bottom + 4) + "px",
        maxHeight: height + "px",
      });
    }
    place();
    window.addEventListener("resize", place);
    document.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      document.removeEventListener("scroll", place, true);
      if (list.isConnected && typeof list.hidePopover === "function" && list.matches(":popover-open")) list.hidePopover();
    };
  }, [open, options.length, loading, lookup.error]);

  function choose(option: PoSuggestion) {
    onSelect(option);
    setFocused(false);
    setSelection({ query: option.name, index: -1 });
  }
  function keyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "Escape" && open) {
      event.preventDefault(); event.stopPropagation(); setFocused(false);
    } else if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault(); setFocused(true);
      if (options.length) {
        const index = event.key === "ArrowDown" ? (active + 1) % options.length : (active <= 0 ? options.length - 1 : active - 1);
        setSelection({ query: value, index });
        panel.current?.querySelectorAll<HTMLButtonElement>("[role=option]")[index]?.scrollIntoView({ block: "nearest" });
      }
    } else if (event.key === "Enter" && open) {
      event.preventDefault();
      if (active >= 0) choose(options[active]);
      else setFocused(false);
    }
  }
  return <div className="po-autocomplete-field">
    <label htmlFor={inputId}>{label}</label>
    <input id={inputId} ref={control} role="combobox" aria-label={ariaLabel} aria-autocomplete="list"
      aria-expanded={open} aria-controls={open ? listId : undefined}
      aria-activedescendant={open && active >= 0 ? listId + "-" + active : undefined}
      required maxLength={200} disabled={disabled} autoComplete="off" value={value}
      placeholder={kind === "suppliers" ? "Ketik nama supplier" : "Ketik nama barang atau SKU"}
      onFocus={() => setFocused(true)} onClick={() => setFocused(true)} onBlur={() => setFocused(false)}
      onKeyDown={keyDown} onChange={event => {
        onChange(event.target.value); setFocused(true); setSelection({ query: event.target.value, index: -1 });
      }}/>
    {open && <div ref={panel} id={listId} className="po-suggestions" popover="manual" role="listbox" aria-label={"Sugesti " + label.toLowerCase()} aria-busy={loading}>
      {loading ? <p role="status">Mencari sugesti...</p> : lookup.error
        ? <p role="status">Sugesti belum dapat dimuat. Nama tetap dapat diketik.</p>
        : options.length ? options.map((option, index) => <button type="button" role="option" tabIndex={-1}
          id={listId + "-" + index} key={option.id + "|" + option.name + "|" + (option.unit ?? "")}
          aria-selected={index === active} className="po-suggestion"
          onPointerDown={event => event.preventDefault()} onClick={() => choose(option)}>
          <strong>{option.name}</strong><small>{caption(option)}</small>
        </button>)
        : <p role="status">Belum ada kecocokan. Nama baru tetap dapat digunakan.</p>}
    </div>}
  </div>;
}

