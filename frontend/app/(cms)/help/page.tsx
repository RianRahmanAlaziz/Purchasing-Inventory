const topics = [
  { question: "Bagaimana membuat purchase order?", answer: "Pilih PT penerbit dan karyawan, lalu ketik nama supplier serta barang atau pilih sugesti riwayat PO dan stok. Barang baru memerlukan kategori dan izin pembuatan. Lengkapi diskon, pajak, serta catatan, lalu simpan draft. Buka detail PO untuk mengajukan persetujuan." },
  { question: "Kapan stok barang berubah?", answer: "Stok bertambah saat penerimaan dikonfirmasi dan berkurang saat distribusi dikirim. Koreksi saldo melalui Sesuaikan stok pada detail barang." },
  { question: "Di mana data disimpan?", answer: "Profil PT, master data, PO, tagihan, pembayaran, dokumen, akun, dan izin tersimpan di server. Pengelola dapat memindahkan profil PT lama melalui tombol Impor PT dari browser." },
  { question: "Kapan tagihan menjadi lunas?", answer: "Tagihan lunas setelah seluruh nominal pembayaran diverifikasi. Pembayaran yang masih menunggu pemeriksaan belum mengurangi sisa tagihan." },
  { question: "Bagaimana mengelola dokumen?", answer: "Unggah PDF, PNG, JPG, atau WebP maksimal 5 MB. Catat pengiriman, penerimaan, lalu verifikasi sesuai hak akses. Unduhan berkas memerlukan akun yang berizin." },
];

export default function HelpPage() {
  return <>
    <div className="page-heading"><div><div className="eyebrow">WORKSPACE / DUKUNGAN</div><h1>Pusat Bantuan</h1><p>Panduan singkat mengelola pengadaan dan persediaan.</p></div></div>
    <section className="panel help-panel"><h2>Pertanyaan umum</h2>
      {topics.map(topic => <details key={topic.question}><summary>{topic.question}</summary><p>{topic.answer}</p></details>)}
    </section>
  </>;
}
