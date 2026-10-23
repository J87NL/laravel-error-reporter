import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import {
    createNetworkErrorGate,
    installErrorReporter,
    networkErrorKind,
} from '../../resources/js/errorReporter.ts';

type Listener = (event: Record<string, unknown>) => void;

const listeners: Record<string, Listener[]> = {};

const sent: Record<string, unknown>[] = [];

class HttpNetworkError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'HttpNetworkError';
    }
}

Object.assign(globalThis, {
    window: {
        location: {
            origin: 'https://shop.test',
            pathname: '/orders/42',
        },
        addEventListener: (type: string, listener: Listener) => {
            listeners[type] = [...(listeners[type] ?? []), listener];
        },
    },
    fetch: (_url: string, init: { body: string }) => {
        sent.push(JSON.parse(init.body));

        return Promise.resolve();
    },
});

function fakeApp(): { config: { errorHandler?: unknown } } {
    return {
        config: {},
    };
}

function reject(reason: unknown): void {
    for (const listener of listeners.unhandledrejection ?? []) {
        listener({ reason });
    }
}

beforeEach(() => {
    sent.length = 0;
});

test('an Inertia network error, a failed fetch and an axios network error are network errors', () => {
    assert.equal(
        networkErrorKind({
            exceptionClass: 'HttpNetworkError',
            message: 'Network error',
            stack: null,
        }),
        'HttpNetworkError',
    );
    assert.equal(
        networkErrorKind({
            exceptionClass: 'TypeError',
            message: 'Failed to fetch',
            stack: null,
        }),
        'TypeError:network',
    );
    assert.equal(
        networkErrorKind({
            exceptionClass: 'AxiosError',
            message: 'Network Error',
            stack: null,
        }),
        'AxiosError:network',
    );
});

test('an ordinary error is not a network error', () => {
    assert.equal(
        networkErrorKind({
            exceptionClass: 'TypeError',
            message: "Cannot read properties of undefined (reading 'id')",
            stack: null,
        }),
        null,
    );
});

test('the gate opens on the third error of a kind within the window', () => {
    let now = 0;
    const gate = createNetworkErrorGate(3, 60000, () => now);

    assert.equal(gate('HttpNetworkError'), false);
    now = 10000;
    assert.equal(gate('HttpNetworkError'), false);
    assert.equal(gate('TypeError:network'), false);
    now = 20000;
    assert.equal(gate('HttpNetworkError'), true);
});

test('errors older than the window do not count', () => {
    let now = 0;
    const gate = createNetworkErrorGate(3, 60000, () => now);

    gate('HttpNetworkError');
    now = 30000;
    gate('HttpNetworkError');
    now = 60000;
    assert.equal(gate('HttpNetworkError'), false);
    now = 61000;
    assert.equal(gate('HttpNetworkError'), true);
});

test('a single network error is not reported, the third one in a minute is, and an ordinary error right away', () => {
    installErrorReporter(fakeApp() as never, {
        endpoint: 'https://collector.test/api/ingest/errors',
        key: 'pub_key',
    });

    reject(new HttpNetworkError('Network error'));
    reject(new HttpNetworkError('Network error'));

    assert.equal(sent.length, 0);

    reject(new HttpNetworkError('Network error'));

    assert.equal(sent.length, 1);
    assert.equal(sent[0].exception_class, 'HttpNetworkError');
    assert.equal('ip' in sent[0], false);
    assert.equal('user_agent' in sent[0], false);

    reject(new RangeError('Invalid array length'));

    assert.equal(sent.length, 2);
    assert.equal(sent[1].exception_class, 'RangeError');
});
