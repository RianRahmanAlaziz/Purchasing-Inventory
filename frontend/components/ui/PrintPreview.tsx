"use client";

import { useRef, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { Button } from "./Button";
import { Icon } from "./Icon";
import { Modal } from "./Modal";

export function PrintPreview({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  const root = useRef<HTMLDivElement>(null);
  const [printing, setPrinting] = useState(false);

  async function print() {
    setPrinting(true);
    try {
      const images = Array.from(root.current?.querySelectorAll("img") ?? []);
      await Promise.all(images.map(image => image.decode().catch(() => {})));
      await document.fonts.ready;
      window.print();
    } finally {
      setPrinting(false);
    }
  }

  return <Modal title={title} className="po-preview-modal" onClose={onClose}>
    <div className="po-preview-toolbar">
      <Button disabled={printing} onClick={() => void print()}>
        <Icon name="file" size={16}/>{printing ? "Menyiapkan..." : "Cetak / Simpan PDF"}
      </Button>
    </div>
    <div className="po-paper-scroll">{children}</div>
    {createPortal(<div ref={root} className="po-print-root" aria-hidden="true">{children}</div>, document.body)}
  </Modal>;
}
