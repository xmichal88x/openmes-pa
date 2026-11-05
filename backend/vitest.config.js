import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// Standalone Vitest config (kept separate from vite.config.js so tests don't
// pull in the Laravel/Tailwind build plugins). Still Vite-powered, so Vite-only
// syntax like `import.meta.glob` in the modules under test resolves. The React
// plugin enables JSX with the automatic runtime for component tests.
export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'node',
        include: ['resources/js/**/*.{test,spec}.{js,jsx}'],
    },
});
