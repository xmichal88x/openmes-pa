import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import PalletBackflushSetting from './PalletBackflushSetting';

const render = (props) => renderToStaticMarkup(createElement(PalletBackflushSetting, props));

// The policy sentence is asserted by a fragment, so re-wording the explanation
// does not silently pass a failing component.
const POLICY_TEXT = 'packing works normally';
const STORED_NOTE = 'not effective';

describe('PalletBackflushSetting', () => {
    it('shows the policy and explains a stored "true" that has no effect', () => {
        const html = render({ stored: true });

        expect(html).toContain('role="switch"');
        expect(html).toContain('aria-checked="false"');
        expect(html).toContain('disabled');
        expect(html).toContain(POLICY_TEXT);
        expect(html).toContain(STORED_NOTE);
    });

    it('keeps the policy and the disabled switch but drops the note for false and null', () => {
        for (const stored of [false, null]) {
            const html = render({ stored });

            expect(html).not.toContain(STORED_NOTE);
            expect(html).toContain(POLICY_TEXT);
            expect(html).toContain('role="switch"');
            expect(html).toContain('aria-checked="false"');
            expect(html).toContain('disabled');
        }
    });

    it('offers no control that can switch the policy on', () => {
        expect(render({ stored: true })).not.toContain('aria-checked="true"');
        expect(render({ stored: false })).not.toContain('aria-checked="true"');
    });
});
