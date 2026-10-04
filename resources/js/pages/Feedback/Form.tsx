import { Head, useForm, usePage } from "@inertiajs/react";
import { Button, Card } from "@/components/ui";

export default function FeedbackForm({
    token,
    practice,
    done,
    publicShown,
}: {
    token: string;
    practice: string;
    done: boolean;
    publicShown: boolean;
}) {
    const { flash } = usePage<{ flash: { success: string | null } }>().props;
    const form = useForm({ rating: 0, comment: "", public_ok: false });

    return (
        <div className="mx-auto max-w-md px-4 py-10">
            <Head title="Your feedback" />
            <Card title={`How was your visit to ${practice}?`}>
                {flash.success || done ? (
                    <p className="text-sm">
                        {flash.success ?? "Thank you — we have your feedback."}
                    </p>
                ) : (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/feedback/${token}`);
                        }}
                        className="flex flex-col gap-3"
                    >
                        <div
                            role="radiogroup"
                            aria-label="Rating"
                            className="flex gap-1 text-3xl"
                        >
                            {[1, 2, 3, 4, 5].map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.rating === n}
                                    aria-label={`${n} stars`}
                                    className={
                                        n <= form.data.rating
                                            ? "text-amber-500"
                                            : "text-gray-300"
                                    }
                                    onClick={() => form.setData("rating", n)}
                                >
                                    ★
                                </button>
                            ))}
                        </div>
                        {form.errors.rating && (
                            <p className="text-xs text-status-danger">
                                {form.errors.rating}
                            </p>
                        )}
                        <textarea
                            aria-label="Comment"
                            placeholder="Anything you'd like to tell us? (optional)"
                            className="min-h-24 rounded-lg border border-line px-3 py-2 text-sm"
                            value={form.data.comment}
                            onChange={(e) =>
                                form.setData("comment", e.target.value)
                            }
                        />
                        {publicShown && (
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={form.data.public_ok}
                                    onChange={(e) =>
                                        form.setData(
                                            "public_ok",
                                            e.target.checked,
                                        )
                                    }
                                />{" "}
                                The practice may show my rating and comment on
                                its website with my initials.
                            </label>
                        )}
                        <p className="text-xs text-muted">
                            Your feedback goes to the practice. Do not include
                            medical details.
                        </p>
                        <Button
                            type="submit"
                            disabled={form.processing || form.data.rating === 0}
                        >
                            Send feedback
                        </Button>
                        {(form.errors as Record<string, string>).token && (
                            <p className="text-xs text-status-danger">
                                {(form.errors as Record<string, string>).token}
                            </p>
                        )}
                    </form>
                )}
            </Card>
        </div>
    );
}
