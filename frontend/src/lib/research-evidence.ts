import type { Source } from "./research-loop.ts";
export function evidenceUrl(value: unknown): string | null {
  if (typeof value !== "string" || value.length > 2000) return null;
  try {
    const url = new URL(value);
    if (!["https:","http:"].includes(url.protocol) || url.username || url.password) return null;
    url.hash = "";
    for (const key of [...url.searchParams.keys()]) if (/^(utm_|fbclid$|gclid$)/i.test(key)) url.searchParams.delete(key);
    url.searchParams.sort();
    return url.href.replace(/%28/gi,"(").replace(/%29/gi,")");
  } catch { return null; }
}
export function groundResearchAnswer(answer: string, sources: Source[]) {
  const valid = sources.filter(source=>Boolean(evidenceUrl(source.url)));
  const allowed = new Set(valid.map(source=>evidenceUrl(source.url)));
  const numbers = new Map(valid.map((source,index)=>[source.citation || index+1,evidenceUrl(source.url)]));
  let removed = 0;
  const text = answer.replace(/\[(\d+)\]\((https?:\/\/(?:[^\s()]|\([^\s()]*\))+)\)/g,(link,number,url)=>{
    if (allowed.has(evidenceUrl(url)) && numbers.get(Number(number)) === evidenceUrl(url)) return link;
    removed++;return "[unverified citation omitted]";
  });
  const read = valid.filter(source=>source.read_status === "read").length;
  const hosts = new Set(valid.map(source=>new URL(source.url).hostname.replace(/^www\./,""))).size;
  const status = `Evidence coverage: ${valid.length} sources, ${hosts} domains, ${read} pages read. Page retrieval does not establish that every claim is supported.`;
  const footer = valid.map((source,index)=>{
    const title = source.title.replace(/[\[\]\\\r\n]/g," ").slice(0,200);
    const label = source.read_status === "read" ? "page read" : source.read_status === "unavailable" ? "page unavailable; snippet only" : "search snippet only";
    return `${source.citation || index+1}. [${title || "Source"}](${source.url.replace(/\(/g,"%28").replace(/\)/g,"%29")}) · ${label}`;
  }).join("\n");
  return {text,footer:`\n\nSources\n\n${footer}\n\n${status}${removed ? " Unrecognized model-generated citation links were omitted; review unsupported claims." : ""}`,removed};
}
