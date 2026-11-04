"use client";
import { useEffect, useRef } from "react";
import { Icon } from "./Icon";
export function Modal({ title, children, onClose, className = "" }: { title: string; children: React.ReactNode; onClose: () => void; className?: string }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => { const dialog = ref.current; dialog?.showModal(); return () => dialog?.close(); }, []);
  return <dialog ref={ref} className={`modal ${className}`} onCancel={onClose} onClick={(event) => { if (event.target === ref.current) onClose(); }}><header><h2>{title}</h2><button className="icon-button" onClick={onClose} aria-label="Tutup"><Icon name="close" /></button></header>{children}</dialog>;
}
