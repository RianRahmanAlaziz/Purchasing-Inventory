"use client";
import { useState } from "react";
import { Icon } from "@/components/ui/Icon";
const datasets = {
 "6 bulan terakhir": { labels: ["Jun", "Jul", "Agu", "Sep", "Okt", "Nov"], values: [42, 56, 45, 75, 62, 88], total: "368.500.000", change: "12,8" },
 "3 bulan terakhir": { labels: ["Sep", "Okt", "Nov"], values: [75, 62, 88], total: "225.000.000", change: "17,3" },
};
export function SpendingChart() {
 const [period, setPeriod] = useState<keyof typeof datasets>("6 bulan terakhir"); const data = datasets[period];
 return <section className="panel spending-panel"><div className="panel-header"><div><h2>Ringkasan Pengadaan</h2><p>Pantau pengeluaran pembelian perusahaan</p></div><select aria-label="Periode grafik" value={period} onChange={e => setPeriod(e.target.value as keyof typeof datasets)}>{Object.keys(datasets).map(p => <option key={p}>{p}</option>)}</select></div><div className="chart-summary"><div><small>Total pengeluaran</small><h3><span>Rp</span> {data.total}</h3></div><span className="change-pill"><Icon name="trend" size={14}/> {data.change}% <span>vs periode lalu</span></span></div><div className="bar-chart"><div className="y-labels">{[100,75,50,25,0].map(v => <span key={v}>{v} jt</span>)}</div><div className="plot"><div className="grid-lines">{[0,1,2,3,4].map(v => <i key={v}/>)}</div><div className="bars">{data.values.map((value,index) => <div className="bar-column" key={`${period}-${index}`}><div className={`bar ${index === data.values.length - 1 ? "current" : ""}`} style={{ height: `${value}%` }} tabIndex={0} aria-label={`${data.labels[index]}: Rp ${value} juta`}><span className="bar-tooltip">Rp {value} juta</span></div><span className="x-label">{data.labels[index]}</span></div>)}</div></div></div><div className="chart-legend"><i/> Pengeluaran pengadaan <span>Dalam jutaan rupiah (IDR)</span></div></section>;
}
export function OrderStatusChart() {
 const segments = [{ name: "Selesai", number: 28, color: "#2e9fff" },{ name: "Dalam proses", number: 12, color: "#85d7ff" },{ name: "Menunggu approval", number: 5, color: "#f0c481" },{ name: "Draft", number: 3, color: "#6a8aaf" }];
 return <section className="panel status-panel"><div className="panel-header"><div><h2>Status Purchase Order</h2><p>Distribusi status bulan ini</p></div><span className="tiny-period">November</span></div><div className="donut-wrap"><div className="donut" role="img" aria-label="48 PO: 28 selesai, 12 dalam proses, 5 menunggu approval, 3 draft"><div><strong>48</strong><span>Total PO</span></div></div></div><div className="status-legend">{segments.map(s => <div key={s.name}><i style={{ background: s.color }}/><span>{s.name}</span><strong>{s.number}</strong><small>{Math.round(s.number/48*100)}%</small></div>)}</div></section>;
}
