import type { App } from 'vue';

export type ErrorReporterOptions = {
    endpoint: string;
    key: string;
    release?: string | null;
    environment?: string | null;
    maxReports?: number;
};

const MAX_MESSAGE_LENGTH = 2000;

const MAX_STACK_LENGTH = 8000;

const IGNORED_MESSAGES = ['Script error.', 'Script error'];

const SECRET_SEGMENT = /^[A-Za-z0-9_-]{32,}$/;

const MASK = '[scrubbed]';

type Reporter = (error: unknown, source: string) => void;

let activeReporter: Reporter | null = null;

let listening = false;

const installedApps = new WeakSet<App>();

type BrowserError = {
    exceptionClass: string;
    message: string;
    stack: string | null;
};

function describe(error: unknown): BrowserError {
    if (error instanceof Error) {
        return {
            exceptionClass: error.name || 'Error',
            message: error.message,
            stack: error.stack ?? null,
        };
    }

    return {
        exceptionClass: 'Error',
        message: typeof error === 'string' ? error : stringify(error),
        stack: null,
    };
}

function stringify(value: unknown): string {
    if (
        typeof value === 'number' ||
        typeof value === 'boolean' ||
        typeof value === 'bigint'
    ) {
        return String(value);
    }

    if (
        typeof value === 'object' &&
        value !== null &&
        'message' in value &&
        typeof value.message === 'string'
    ) {
        return value.message;
    }

    return Object.prototype.toString.call(value);
}

function currentAddress(): string {
    const path = window.location.pathname
        .split('/')
        .map((segment) => (SECRET_SEGMENT.test(segment) ? MASK : segment))
        .join('/');

    return `${window.location.origin}${path}`;
}

export function installErrorReporter(
    app: App,
    options: ErrorReporterOptions | null | undefined,
): void {
    if (
        options === null ||
        options === undefined ||
        options.endpoint === '' ||
        options.key === '' ||
        typeof window === 'undefined' ||
        installedApps.has(app)
    ) {
        return;
    }

    installedApps.add(app);

    const maxReports = options.maxReports ?? 10;
    const reported = new Set<string>();

    const send = (error: unknown, source: string): void => {
        const described = describe(error);

        if (IGNORED_MESSAGES.includes(described.message)) {
            return;
        }

        const signature = `${described.exceptionClass}:${described.message}`;

        if (reported.has(signature) || reported.size >= maxReports) {
            return;
        }

        reported.add(signature);

        void fetch(options.endpoint, {
            method: 'POST',
            mode: 'no-cors',
            credentials: 'omit',
            keepalive: true,
            headers: {
                'Content-Type': 'text/plain',
            },
            body: JSON.stringify({
                key: options.key,
                exception_class: described.exceptionClass,
                message: described.message.slice(0, MAX_MESSAGE_LENGTH),
                stack: described.stack?.slice(0, MAX_STACK_LENGTH) ?? null,
                url: currentAddress(),
                release: options.release ?? null,
                environment: options.environment ?? null,
                context: {
                    source,
                },
            }),
        }).catch(() => undefined);
    };

    const report: Reporter = (error, source) => {
        try {
            send(error, source);
        } catch {
            return;
        }
    };

    const previousHandler = app.config.errorHandler;

    app.config.errorHandler = (error, instance, info) => {
        report(error, 'vue');

        if (previousHandler) {
            previousHandler(error, instance, info);

            return;
        }

        console.error(error);
    };

    activeReporter = report;

    if (listening) {
        return;
    }

    listening = true;

    window.addEventListener('error', (event) => {
        activeReporter?.(event.error ?? event.message, 'window');
    });

    window.addEventListener('unhandledrejection', (event) => {
        activeReporter?.(event.reason, 'promise');
    });
}
