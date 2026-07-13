'use client';

/**
 * ThemeToggle — dark/light mode toggle button.
 *
 * Cycles through light → dark → system and back. Displays a Sun icon in light
 * mode, a Moon icon in dark mode, and a Monitor icon in system mode. Clicking
 * toggles between light and dark (system is preserved when set programmatically
 * from the initial load but the toggle only switches light ↔ dark for a simple
 * UX).
 *
 * Theme preference is persisted in localStorage under `pmp-theme` by the
 * Zustand uiStore persist middleware.
 *
 * Validates: Requirements 22.2 (dark/light mode toggle with localStorage persistence)
 */

import { Sun, Moon, Monitor } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useUIStore, type Theme } from '@/store/uiStore';

const ICON_CLASS = 'h-4 w-4';

function ThemeIcon({ theme }: { theme: Theme }) {
  if (theme === 'dark') return <Moon className={ICON_CLASS} aria-hidden="true" />;
  if (theme === 'system') return <Monitor className={ICON_CLASS} aria-hidden="true" />;
  return <Sun className={ICON_CLASS} aria-hidden="true" />;
}

function themeLabel(theme: Theme): string {
  if (theme === 'dark') return 'Switch to light mode (currently dark)';
  if (theme === 'system') return 'Switch to dark mode (currently system)';
  return 'Switch to dark mode (currently light)';
}

function nextTheme(theme: Theme): Theme {
  // Toggle light ↔ dark; if currently system, go to dark
  if (theme === 'light') return 'dark';
  return 'light';
}

export function ThemeToggle() {
  const theme = useUIStore((state) => state.theme);
  const setTheme = useUIStore((state) => state.setTheme);

  return (
    <Button
      variant="ghost"
      size="icon"
      className="h-9 w-9 shrink-0"
      onClick={() => setTheme(nextTheme(theme))}
      aria-label={themeLabel(theme)}
      title={themeLabel(theme)}
    >
      <ThemeIcon theme={theme} />
    </Button>
  );
}
