import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { Providers } from "@/providers";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Procurement Management Platform",
  description: "Enterprise-grade multi-tenant procurement management system",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <head>
        {/*
         * Flash-of-wrong-theme prevention script.
         *
         * Runs synchronously before the first paint to read the `pmp-theme`
         * localStorage key and apply the correct `.dark` or `.light` class to
         * <html>. This avoids a brief white flash when the user has chosen dark
         * mode, because the Zustand hydration (and the ThemeProvider effect)
         * would otherwise run after the first render.
         *
         * The script mirrors the logic in ThemeProvider.tsx:
         *   - 'dark'   → add class "dark"
         *   - 'light'  → add class "light"
         *   - 'system' → check prefers-color-scheme and add "dark" or "light"
         *   - absent   → default to system preference
         *
         * The Zustand persist key is "pmp-theme" and the JSON payload is
         * { state: { theme: "light"|"dark"|"system", ... }, version: 0 }.
         *
         * Validates: Requirements 22.2 (dark/light mode localStorage persistence)
         */}
        <script
          dangerouslySetInnerHTML={{
            __html: `
(function () {
  try {
    var raw = localStorage.getItem('pmp-theme');
    var theme = 'system';
    if (raw) {
      try {
        var parsed = JSON.parse(raw);
        if (parsed && parsed.state && parsed.state.theme) {
          theme = parsed.state.theme;
        }
      } catch (_) {}
    }
    var resolved;
    if (theme === 'dark') {
      resolved = 'dark';
    } else if (theme === 'light') {
      resolved = 'light';
    } else {
      resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.classList.remove('dark', 'light');
    document.documentElement.classList.add(resolved);
    document.documentElement.setAttribute('data-theme', resolved);
  } catch (_) {}
})();
            `.trim(),
          }}
        />
      </head>
      <body className="min-h-full flex flex-col">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
