import { Head, usePoll } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

/**
 * Waiting-room TV: ticket numbers only, never patient names.
 */
export default function Display({ provider, calling, waiting }: { provider: string; calling: { ticket: string; to: string }[]; waiting: { ticket: string; stage: string }[] }) {
    usePoll(5000);

    return (
        <main className="flex min-h-screen flex-col gap-6 bg-ink p-10 text-white">
            <Head title="Queue" />
            <h1 className="text-3xl font-semibold">{provider}</h1>
            <div className="flex flex-1 gap-8">
                <section className="flex flex-[1.3] flex-col gap-4">
                    <h2 className="text-xl text-[#9FB2B9]">Now calling</h2>
                    {calling.length === 0 && <p className="text-2xl text-[#9FB2B9]">Please wait for your number.</p>}
                    {calling.map((c, i) => (
                        <div key={c.ticket} className={`flex items-center gap-6 rounded-2xl px-8 py-6 ${i === 0 ? 'bg-teal' : 'bg-ink-2'}`}>
                            <span className="text-6xl font-bold tracking-wide">{c.ticket}</span>
                            <ArrowRight className="size-8" aria-hidden="true" />
                            <span className="text-3xl font-semibold">{c.to}</span>
                        </div>
                    ))}
                </section>
                <section className="flex-1 rounded-2xl bg-[#16262E] p-6">
                    <h2 className="mb-3 text-xl text-[#9FB2B9]">Waiting</h2>
                    <ul className="text-2xl">
                        {waiting.map((w) => (
                            <li key={w.ticket} className="flex border-b border-[#26404C] py-3">
                                <span className="w-32 font-semibold">{w.ticket}</span>
                                <span className="text-[#BFD0D5]">{w.stage}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
            <p className="text-lg text-[#9FB2B9]">Ticket numbers only — no names on screen.</p>
        </main>
    );
}
