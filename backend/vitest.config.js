import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// Standalone Vitest config (kept separate from vite.config.js so tests don't
// pull in the Laravel/Tailwind build plugins). Still Vite-powered, so Vite-only
// syntax like `import.meta.glob` in the modules under test resolves. The React
// plugin enables JSX with the automatic runtime for component tests.
export default defineConfig({
    plugins: [react()],
    // @openmes/ui ships twin-platform source (index.web.jsx / index.native.tsx)
    // and its barrel imports directories, so the web extensions vite.config.js
    // already prefers must be listed here too or the package cannot load under
    // Vitest. dedupe pins the package's react import to this app's copy.
    resolve: {
        extensions: ['.web.jsx', '.web.tsx', '.web.js', '.web.ts', '.mjs', '.js', '.mts', '.ts', '.jsx', '.tsx', '.json'],
        dedupe: ['react', 'react-dom', '@tanstack/react-table', 'lucide-react', '@dnd-kit/react'],
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/*.{test,spec}.{js,jsx}'],
    },
});
