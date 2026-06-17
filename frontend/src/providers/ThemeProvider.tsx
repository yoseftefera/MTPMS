'use client';

/**
 * Theme provider.
 *
 * Reads the user's theme preference from the Zustand uiStore (persisted under
 * the `pmp-theme` localStorage key) and applies it as a `data-theme` attribute
 * on the root <html> element.
 *
 * When theme is 'system', the OS preference is respected via the
 * `prefers-color-scheme` media query.
 *
 * Validates: Requirements 22.2 (dark/light mode toggle with localStorage persistence)
 */

import { useEffect } from 'react';
import { useUIStore } from '@/store/uiStore';

interface ThemeProviderProps {
  children: React.ReactNode;
}

export function ThemeProvider({ children }: ThemeProviderProps) {
  const theme = useUIStore((state) => state.theme);

  useEffect(() => {
    const root = document.documentElement;

    const applyTheme = (resolved: 'light' | 'dark') => {
      root.classList.remove('light', 'dark');
      root.classList.add(resolved);
      root.setAttribute('data-theme', resolved);
    };

    if (theme === 'system') {
      const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
      applyTheme(mediaQuery.matches ? 'dark' : 'light');

      const handler = (e: MediaQueryListEvent) => applyTheme(e.matches ? 'dark' : 'light');
      mediaQuery.addEventListener('change', handler);
      return () => mediaQuery.removeEventListener('change', handler);
    } else {
      applyTheme(theme);
    }
  }, [theme]);

  return <>{children}</>;
}
