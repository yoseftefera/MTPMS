/**
 * Zustand UI store.
 *
 * Manages global UI state: sidebar open/collapsed, active theme.
 * Theme preference is persisted to localStorage under the `pmp-theme` key
 * as required by the design spec.
 *
 * Validates: Requirements 22.2 (dark/light mode), 22.3 (responsive layout)
 */

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

export type Theme = 'light' | 'dark' | 'system';

interface UIState {
  sidebarOpen: boolean;
  sidebarCollapsed: boolean;
  theme: Theme;

  // Actions
  toggleSidebar: () => void;
  setSidebarOpen: (open: boolean) => void;
  toggleSidebarCollapsed: () => void;
  setSidebarCollapsed: (collapsed: boolean) => void;
  setTheme: (theme: Theme) => void;
}

export const useUIStore = create<UIState>()(
  persist(
    (set) => ({
      sidebarOpen: true,
      sidebarCollapsed: false,
      theme: 'system',

      toggleSidebar: () => set((state) => ({ sidebarOpen: !state.sidebarOpen })),
      setSidebarOpen: (open) => set({ sidebarOpen: open }),

      toggleSidebarCollapsed: () => set((state) => ({ sidebarCollapsed: !state.sidebarCollapsed })),
      setSidebarCollapsed: (collapsed) => set({ sidebarCollapsed: collapsed }),

      setTheme: (theme) => set({ theme }),
    }),
    {
      name: 'pmp-theme',
      storage: createJSONStorage(() => localStorage),
      // Only persist theme and sidebar collapsed state
      partialize: (state) => ({
        theme: state.theme,
        sidebarCollapsed: state.sidebarCollapsed,
      }),
    },
  ),
);

// Selector helpers
export const selectTheme = (state: UIState) => state.theme;
export const selectSidebarOpen = (state: UIState) => state.sidebarOpen;
export const selectSidebarCollapsed = (state: UIState) => state.sidebarCollapsed;
