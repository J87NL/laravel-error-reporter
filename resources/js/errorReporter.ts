import type { App } from 'vue';

export type ErrorReporterOptions = {
    endpoint: string;
    key: string;
    release?: string | null;
    environment?: string | null;
    maxReports?: number;
};

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
        message: typeof error === 'string' ? error : String(error),
        stack: null,
    };
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
        typeof window === 'undefined'
    ) {
        return;
    }

    const maxReports = options.maxReports ?? 10;
    const reported = new Set<string>();

    const report = (error: unknown, source: string): void => {
        const described = describe(error);
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
                message: described.message,
                stack: described.stack,
                url: `${window.location.origin}${window.location.pathname}`,
                release: options.release ?? null,
                environment: options.environment ?? null,
                context: {
                    source,
                },
            }),
        }).catch(() => undefined);
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

    window.addEventListener('error', (event) => {
        report(event.error ?? event.message, 'window');
    });

    window.addEventListener('unhandledrejection', (event) => {
        report(event.reason, 'promise');
    });
}
