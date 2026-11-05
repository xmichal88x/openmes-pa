import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import PreflightWarningBanner from './PreflightWarningBanner';

const render = (props) => renderToStaticMarkup(createElement(PreflightWarningBanner, props));

describe('PreflightWarningBanner', () => {
    it('renders nothing for non-string or blank messages', () => {
        expect(render({ message: null })).toBe('');
        expect(render({ message: '' })).toBe('');
        expect(render({ message: '   ' })).toBe('');
        expect(render({ message: { x: 1 } })).toBe('');
    });

    it('renders the message and a dismiss control for a normal string', () => {
        const html = render({ message: 'Shared sheet could not be verified' });

        expect(html).toContain('Shared sheet could not be verified');
        expect(html).toContain('aria-label="Dismiss"');
    });

    it('exposes itself as a polite live status region', () => {
        const html = render({ message: 'Shared sheet could not be verified' });

        expect(html).toContain('role="status"');
        expect(html).toContain('aria-live="polite"');
    });

    it('renders message HTML as escaped text', () => {
        const html = render({ message: '<b>x</b>' });

        expect(html).toContain('&lt;b&gt;x&lt;/b&gt;');
        expect(html).not.toContain('<b>x</b>');
    });
});
