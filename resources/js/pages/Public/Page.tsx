import { Head, Link } from '@inertiajs/react';
import { Logo } from '@/components/Logo';

export default function PublicPage({ title, description, html }: { title: string; description: string | null; html: string }) {
    return (
        <div className="min-h-screen bg-white">
            <Head title={title}>{description && <meta name="description" content={description} />}</Head>
            <header className="flex items-center gap-6 border-b border-[#EBF0EE] px-10 py-4 text-sm">
                <Link href="/">
                    <Logo />
                </Link>
                <Link href="/find-care">Find care</Link>
                <Link href="/pricing">Pricing</Link>
                <Link href="/login" className="ml-auto">
                    Sign in
                </Link>
            </header>
            {/* Server-sanitised CMS HTML (scripts, handlers and remote resources stripped). */}
            <main className="cms mx-auto max-w-4xl px-6 py-12 [&_h1]:text-4xl [&_h1]:font-semibold [&_h2]:mt-8 [&_h2]:text-2xl [&_h2]:font-semibold [&_p]:mt-3 [&_p]:text-lg [&_p]:text-muted" dangerouslySetInnerHTML={{ __html: html }} />
        </div>
    );
}
