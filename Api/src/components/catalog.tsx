"use client";

import { useEffect, useMemo, useState } from "react";
import { ArrowRight, Cpu, Search } from "lucide-react";
import Link from "next/link";
import { api, Model } from "@/lib/api";

export default function Catalog() {
  const [models, setModels] = useState<Model[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("All");
  useEffect(() => { api<{data: Model[]}>("developer/catalog")
    .then((x) => setModels(x.data))
    .catch((e) => setError(e.message)).finally(() => setLoading(false)); }, []);
  const categories = useMemo(() => ["All", ...new Set(models.map((m) => m.category))].filter(Boolean), [models]);
  const visible = models.filter((m) => (category === "All" || m.category === category) &&
    (m.name + " " + m.id + " " + (m.description || "")).toLowerCase().includes(query.toLowerCase()));
  return <main className="page">
    <div className="page-heading"><div><div className="eyebrow">HOSTED INFERENCE</div><h1>Model catalog</h1><p>Discover hosted models and their integration capabilities. Listed models may require additional provider access.</p></div><span className="label-chip"><Cpu size={14}/> {models.length} models</span></div>
    {error && <p className="alert error" role="alert">{error}</p>}
    <div className="catalog-controls"><label className="search"><Search size={18}/><input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search models and capabilities…" aria-label="Search models"/></label><select aria-label="Filter by category" value={category} onChange={(e) => setCategory(e.target.value)}>{categories.map((item) => <option key={item} value={item}>{item}</option>)}</select></div>
    {loading ? <p className="empty">Loading model catalog…</p> : visible.length === 0 ? <p className="empty">No models match your search.</p> : <div className="model-grid">{visible.map((m) => <article className="panel model-card" key={m.id}><div className="model-top"><span className="model-icon"><Cpu size={19}/></span><span className={"model-status " + (m.available ? "active" : "idle")}>{m.available ? "Integration ready" : "Not configured"}</span></div><h3>{m.name}</h3><code>{m.id}</code><p>{m.description || "Hosted NVIDIA model available in the OpenMindAI catalog."}</p><div className="model-footer"><span>{m.category} · {m.operation}</span><span>{m.provider_access === "verified" ? "Provider verified" : "Provider unverified"}</span></div></article>)}</div>}
    <div className="notice-line">A model being listed or marked integration ready does not guarantee your NVIDIA account has inference access. <Link href="/docs">View integration guide <ArrowRight size={15}/></Link></div>
  </main>;
}
