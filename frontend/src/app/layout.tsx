import type { Metadata } from "next";
import "@fontsource-variable/dm-sans";
import "@fontsource-variable/manrope";
import "./globals.css";
export const metadata: Metadata = {
  title: "Openmindai",
  icons: { icon: "/openmindai-logo.png", apple: "/openmindai-logo.png" },
  description:
    "A quiet space to think, create, and explore with your own AI models.",
};
export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
