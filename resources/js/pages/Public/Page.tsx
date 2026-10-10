import { SiteLayout } from "@/components/site/SiteLayout";
import type { SiteInfo } from "@/components/site/types";

export default function PublicPage({
    title,
    description,
    html,
    site,
}: {
    title: string;
    description: string | null;
    html: string;
    site: SiteInfo;
}) {
    return (
        <SiteLayout site={site} title={title} description={description ?? ""}>
            {/* Server-sanitised CMS HTML (scripts, handlers and remote resources stripped). */}
            <div
                className="cms mx-auto max-w-4xl px-6 py-12 [&_h1]:text-4xl [&_h1]:font-semibold [&_h2]:mt-8 [&_h2]:text-2xl [&_h2]:font-semibold [&_p]:mt-3 [&_p]:text-lg [&_p]:text-muted"
                dangerouslySetInnerHTML={{ __html: html }}
            />
        </SiteLayout>
    );
}
