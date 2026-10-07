import { SiteLayout } from "@/components/site/SiteLayout";
import { SiteSections } from "@/components/site/SiteSections";
import type { SiteInfo, SiteSection } from "@/components/site/types";

export default function PlatformSite({
    page,
    sections,
    site,
}: {
    page: { title: string; description: string };
    sections: SiteSection[];
    site: SiteInfo;
}) {
    return (
        <SiteLayout
            site={site}
            title={page.title}
            description={page.description}
        >
            <SiteSections sections={sections} site={site} />
        </SiteLayout>
    );
}
