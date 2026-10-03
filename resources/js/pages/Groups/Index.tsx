import { Head, Link } from "@inertiajs/react";
import { Card } from "@/components/ui";

export default function GroupIndex({
    groups,
}: {
    groups: { id: number; name: string }[];
}) {
    return (
        <div className="mx-auto max-w-3xl px-6 py-8">
            <Head title="Groups" />
            <h1 className="mb-4 text-2xl font-semibold">Your groups</h1>
            <Card>
                {groups.length === 0 && (
                    <p className="text-sm text-muted">
                        You are not a group admin.
                    </p>
                )}
                {groups.map((g) => (
                    <Link
                        key={g.id}
                        href={`/groups/${g.id}`}
                        className="block py-1 text-teal-deep"
                    >
                        {g.name}
                    </Link>
                ))}
            </Card>
        </div>
    );
}
