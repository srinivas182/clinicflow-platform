import { usePage } from "@inertiajs/react";
import type { SharedProps } from "@/types";

export function Flash() {
    const { flash, errors } = usePage<SharedProps>().props;
    const firstError = flash.error ?? Object.values(errors ?? {})[0];

    return (
        <>
            {flash.success && (
                <div
                    role="status"
                    className="mb-4 rounded-lg border border-mint-2 bg-mint px-4 py-3 text-sm text-teal-deep"
                >
                    {flash.success}
                </div>
            )}
            {firstError && (
                <div
                    role="alert"
                    className="mb-4 rounded-lg border border-danger-line bg-status-danger-wash px-4 py-3 text-sm text-status-danger"
                >
                    {firstError}
                </div>
            )}
        </>
    );
}
