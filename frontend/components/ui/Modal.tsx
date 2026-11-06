"use client";
import { useEffect, useId, useRef } from "react";
import { Icon } from "./Icon";

export function Modal({ title, description, children, onClose, className = "" }: {
  title: string;
  description?: string;
  children: React.ReactNode;
  onClose: () => void;
  className?: string;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const titleId = useId();
  const descriptionId = titleId + "-description";

  useEffect(() => {
    const dialog = ref.current;
    dialog?.showModal();
    heading.current?.focus({ preventScroll: true });
    return () => dialog?.close();
  }, []);

  return <dialog ref={ref} className={`modal ${className}`} aria-labelledby={titleId}
    aria-describedby={description ? descriptionId : undefined}
    onCancel={event => { event.preventDefault(); onClose(); }}
    onClick={event => { if (event.target === ref.current) onClose(); }}>
    <header>
      <div>
        <h2 id={titleId} ref={heading} tabIndex={-1}>{title}</h2>
        {description && <p id={descriptionId} className="modal-description">{description}</p>}
      </div>
      <button type="button" className="icon-button" onClick={onClose} aria-label="Tutup"><Icon name="close"/></button>
    </header>
    {children}
  </dialog>;
}
