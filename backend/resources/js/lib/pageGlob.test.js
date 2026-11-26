import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

// app.jsx imports every page eagerly, so a *.test.jsx left under Pages/ ends up
// in the production bundle -- and it drags vitest in with it, which is not a
// page component: the built app rendered a blank screen. Tests may sit next to
// the code they cover, but never inside a Pages tree.

// This file lives in resources/js/lib, so three levels up is backend/.
const backend = fileURLToPath(new URL('../../..', import.meta.url));
const corePages = join(backend, 'resources/js/Pages');
const modules = join(backend, 'modules');

function entriesIn(dir) {
    try {
        return readdirSync(dir, { withFileTypes: true });
    } catch (error) {
        // A checkout without a modules directory has nothing to guard.
        if (error.code === 'ENOENT') return [];
        throw error;
    }
}

function filesEndingWith(dir, suffix, found = []) {
    for (const entry of entriesIn(dir)) {
        const path = join(dir, entry.name);

        if (entry.isDirectory()) {
            filesEndingWith(path, suffix, found);
        } else if (entry.isFile() && entry.name.endsWith(suffix)) {
            found.push(relative(backend, path));
        }
    }

    return found;
}

describe('page globs', () => {
    it('leaves no test file inside the core Pages tree', () => {
        // Guard against a vacuous pass if the path above ever drifts.
        expect(existsSync(corePages)).toBe(true);
        expect(filesEndingWith(corePages, '.test.jsx')).toEqual([]);
    });

    it('leaves no test file inside any module Pages tree', () => {
        const pages = entriesIn(modules)
            .filter((entry) => entry.isDirectory())
            .map((entry) => join(modules, entry.name, 'resources/js/Pages'));

        expect(pages.flatMap((dir) => filesEndingWith(dir, '.test.jsx'))).toEqual([]);
    });

    it('still excludes test files from the globs, should one ever land there', () => {
        const app = readFileSync(join(backend, 'resources/js/app.jsx'), 'utf8');

        expect(app).toContain('!./Pages/**/*.test.jsx');
        expect(app).toContain('!../../modules/*/resources/js/Pages/**/*.test.jsx');
    });
});
