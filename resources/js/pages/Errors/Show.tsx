import { Head, Link } from "@inertiajs/react";

const SERVER_ERROR = {
    title: "Something went wrong",
    message:
        "An unexpected error happened on our side and our team has been notified. Please try again in a few minutes.",
};

const COPY: Record<number, { title: string; message: string }> = {
    403: {
        title: "You don’t have access",
        message:
            "Your account can’t open this page. If you think you should have access, ask your practice owner or administrator.",
    },
    404: {
        title: "Page not found",
        message: "The page you’re looking for doesn’t exist or has moved.",
    },
    419: {
        title: "Your session expired",
        message:
            "For your security, the page timed out. Go back and try again — anything you hadn’t saved may need to be entered again.",
    },
    429: {
        title: "Too many requests",
        message:
            "You’ve made a lot of requests in a short time. Please wait a minute and try again.",
    },
    500: {
        title: "Something went wrong",
        message:
            "An unexpected error happened on our side and our team has been notified. Please try again in a few minutes.",
    },
    503: {
        title: "Back shortly",
        message:
            "Dr Business Flow is being updated or is temporarily unavailable. Please try again in a few minutes.",
    },
};

/** In-app error page (shown when an error happens while moving around inside Dr Business Flow). */
export default function ErrorPage({
    status,
    message,
}: {
    status: number;
    message?: string | null;
}) {
    const copy = COPY[status] ?? SERVER_ERROR;

    return (
        <main className="flex min-h-screen items-center justify-center bg-paper p-6 text-ink">
            <Head title={copy.title} />
            <div className="w-full max-w-md rounded-2xl border border-line bg-surface p-8 text-center">
                <span className="inline-block rounded-full bg-mint px-3 py-1 text-xs font-semibold tracking-wide text-teal-deep">
                    Error {status}
                </span>
                <h1 className="mt-4 mb-2 text-2xl font-semibold">
                    {copy.title}
                </h1>
                <p className="mb-6 text-muted">{message || copy.message}</p>
                <div className="flex flex-wrap justify-center gap-3">
                    <Link
                        href="/"
                        className="rounded-lg bg-teal px-4 py-2 font-semibold text-white"
                    >
                        Go to the home page
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.history.back()}
                        className="rounded-lg border border-line px-4 py-2 font-semibold"
                    >
                        Go back
                    </button>
                </div>
            </div>
        </main>
    );
}
