import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

// Permanent gate for the class of bug that shipped in r20: a free identifier
// used in JSX (`preflightWarning`) passed `vite build` and crashed at runtime.
//
// Keep the gate narrow and deterministic:
// - no-undef / react/jsx-no-undef are hard errors (the r20 crash class).
// - react-hooks rules are registered so existing eslint-disable directives are
//   valid, but kept off/warn for now: the tree has pre-existing violations
//   (e.g. conditional usePage in WorkOrderDetail.jsx:708) that are tracked as a
//   separate repair, not silently enabled by this gate.
export default [
    {
        ignores: ['node_modules/**', 'vendor/**', 'public/build/**'],
    },
    {
        files: ['resources/js/**/*.{js,jsx}'],
        languageOptions: {
            ecmaVersion: 2023,
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
            globals: { ...globals.browser, ...globals.node },
        },
        linterOptions: {
            reportUnusedDisableDirectives: 'off',
        },
        plugins: {
            react,
            'react-hooks': reactHooks,
        },
        settings: { react: { version: 'detect' } },
        rules: {
            'no-undef': 'error',
            'react/jsx-no-undef': 'error',
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'off',
        },
    },
];
