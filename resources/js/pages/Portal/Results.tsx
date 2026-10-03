import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card } from '@/components/ui';
import { PortalLayout } from '@/layouts/PortalLayout';

interface Order {
    id: string;
    patient: string;
    date: string | null;
    status: string;
    note: string | null;
    comment: string | null;
    results: { name: string; value: string | null; unit: string; reference: string; flag: string | null }[];
    canRequest: boolean;
    requested: boolean;
    downloads: { report: boolean; labReport: boolean } | null;
    bookUrl: string | null;
}

export default function Results({ providerName, orders }: { providerName: string; orders: Order[] }) {
    return (
        <PortalLayout provider={providerName}>
            <Head title="Results" />
            <h1 className="mb-1 text-2xl font-semibold">Your results</h1>
            <p className="mb-5 text-sm text-muted">Your doctor reviews results before you see them.</p>
            <Flash />
            <div className="flex flex-col gap-3">
                {orders.length === 0 && <p className="text-sm text-muted">No lab tests yet.</p>}
                {orders.map((o) => (
                    <Card key={o.id} title={`${o.date} · ${o.patient}`} aside={<Badge tone={o.results.length ? 'success' : 'neutral'}>{o.status}</Badge>}>
                        {o.note && <p className="mb-2 text-sm">{o.note}</p>}
                        {o.bookUrl && (
                            <a href={o.bookUrl}>
                                <Button size="sm">Book a follow-up</Button>
                            </a>
                        )}
                        {o.results.length > 0 && (
                            <table className="w-full text-sm">
                                <tbody>
                                    {o.results.map((r, i) => (
                                        <tr key={i} className="border-t border-[#EBF0EE] first:border-0">
                                            <td className="py-1.5">{r.name}</td>
                                            <td className="py-1.5 font-medium">
                                                {r.value} {r.unit}
                                            </td>
                                            <td className="py-1.5 text-xs text-muted">{r.reference}</td>
                                            <td className="py-1.5">{r.flag && r.flag !== 'normal' && <Badge tone="warning">{r.flag.replace('_', ' ')}</Badge>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {o.comment && <p className="mt-2 text-sm text-muted">Doctor: {o.comment}</p>}
                        <div className="mt-3 flex gap-2">
                            {o.downloads?.report && (
                                <a href={`/my/results/${o.id}/download/report`}>
                                    <Button size="sm" variant="secondary" icon={<Download className="size-3.5" />}>
                                        Results PDF
                                    </Button>
                                </a>
                            )}
                            {o.downloads?.labReport && (
                                <a href={`/my/results/${o.id}/download/lab`}>
                                    <Button size="sm" variant="secondary" icon={<Download className="size-3.5" />}>
                                        Lab's report
                                    </Button>
                                </a>
                            )}
                            {o.canRequest && (
                                <Button size="sm" onClick={() => router.post(`/my/results/${o.id}/request`, {}, { preserveScroll: true })}>
                                    Request my results
                                </Button>
                            )}
                            {o.requested && o.results.length === 0 && <span className="text-xs text-muted">You asked your doctor for these results.</span>}
                        </div>
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
