import { Head } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type { SiteInfo } from './types';

export function SiteLayout({ site, title, description, children }: { site: SiteInfo; title: string; description: string; children: ReactNode }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="min-h-screen bg-white">
            <Head title={title}>
                <meta name="description" content={description} />
            </Head>
            <header className="sticky top-0 z-20 border-b border-[#EBF0EE] bg-white/95 backdrop-blur">
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-6 py-4">
                    <a href="/" className="flex items-center gap-2 text-lg font-semibold">
                        <span className="grid size-8 place-items-center rounded-lg font-bold text-white" style={{ background: site.colour }}>
                            {site.name.charAt(0)}
                        </span>
                        {site.name}
                    </a>
                    <nav aria-label="Main" className={`${open ? 'flex' : 'hidden'} absolute top-16 right-0 left-0 flex-col gap-1 border-b border-line bg-white p-4 md:static md:flex md:flex-row md:border-0 md:p-0`}>
                        {site.menu.map((m) => (
                            <a key={m.href} href={m.href} className="rounded-md px-3 py-2 text-sm text-muted hover:text-ink">
                                {m.label}
                            </a>
                        ))}
                    </nav>
                    <a href={site.cta.href} className="ml-auto hidden rounded-lg px-4 py-2 text-sm font-semibold text-white md:inline-block" style={{ background: site.colour }}>
                        {site.cta.label}
                    </a>
                    <button type="button" aria-label="Menu" className="ml-auto md:hidden" onClick={() => setOpen(!open)}>
                        <Menu className="size-6" />
                    </button>
                </div>
            </header>
            <main>{children}</main>
            <footer className="border-t border-line bg-paper">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-6 py-8 text-sm text-muted">
                    <span>
                        © {new Date().getFullYear()} {site.name}
                    </span>
                    {site.footer.map((f) => (
                        <a key={f.href} href={f.href} className="hover:text-ink">
                            {f.label}
                        </a>
                    ))}
                    {site.poweredBy && <span className="ml-auto text-xs">Powered by Clinic Flow</span>}
                </div>
            </footer>
        </div>
    );
}
