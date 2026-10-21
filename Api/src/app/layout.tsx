import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "OpenMindAI Developers | Build with OpenMindAI",
  description: "Create API keys, connect coding assistants, explore hosted models, and monitor usage.",
  robots: { index: true, follow: true }
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en"><body>{children}</body></html>;
}
