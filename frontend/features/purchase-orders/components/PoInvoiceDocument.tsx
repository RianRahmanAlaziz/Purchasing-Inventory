"use client";

import { useId, useRef, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { api } from "@/lib/api";

type Props = {
  purchaseOrderId: number;
  fileName: string | null;
  canUpload: boolean;
  loading: boolean;
  saved: () => void;
};

export function PoInvoiceDocument({ purchaseOrderId, fileName, canUpload, loading, saved }: Props) {
  const fieldId = useId();
  const input = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!file || busy || loading) return;
    setError("");
    setNotice("");
    if (file.size > 5 * 1024 * 1024) {
      setError("Ukuran berkas invoice maksimal 5 MB.");
      return;
    }

    setBusy(true);
    try {
      const form = new FormData();
      form.set("file", file);
      await api(`purchase-orders/${purchaseOrderId}/invoice-document`, { method: "POST", body: form });
      setFile(null);
      if (input.current) input.current.value = "";
      setNotice("Dokumentasi invoice berhasil diunggah.");
      saved();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Berkas invoice gagal diunggah.");
    } finally {
      setBusy(false);
    }
  }

  return <section className="po-invoice-document">
    <h3>Dokumentasi invoice</h3>
    {fileName ? <a className="text-link" href={`/api/purchase-orders/${purchaseOrderId}/invoice-document`}>{fileName}</a>
      : <p className="form-hint">Belum ada berkas invoice.</p>}
    {canUpload && <form className="po-invoice-upload" onSubmit={event => void upload(event)}>
      <label htmlFor={fieldId}>Berkas invoice</label>
      <input ref={input} id={fieldId} type="file" accept=".pdf,.png,.jpg,.jpeg,.webp" required disabled={busy || loading}
        aria-describedby={fieldId + "-hint"} onChange={event => {
          setFile(event.target.files?.[0] ?? null);
          setError("");
          setNotice("");
        }}/>
      <small id={fieldId + "-hint"} className="form-hint">PDF, PNG, JPG, atau WebP. Maksimal 5 MB.</small>
      <Button type="submit" disabled={!file || busy || loading}>{busy ? "Mengunggah..." : fileName ? "Ganti berkas invoice" : "Unggah invoice"}</Button>
    </form>}
    {notice && <p className="form-hint" role="status">{notice}</p>}
    {error && <p className="form-error" role="alert">{error}</p>}
  </section>;
}
