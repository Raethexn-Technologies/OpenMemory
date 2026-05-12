import { vi } from 'vitest';

globalThis.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

globalThis.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

window.confirm = vi.fn(() => true);
