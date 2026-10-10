<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support\Website;

use App\Domains\Platform\Models\CmsPage;

/**
 * Default content for clinicflow.co.za. Created once; the super admin edits
 * everything afterwards. Claims are deliberately factual — no invented
 * customer numbers or testimonials.
 */
final class PlatformWebsite
{
    /**
     * Creates any default page that does not exist yet. Never overwrites edits.
     */
    public static function seed(): int
    {
        $created = 0;
        foreach (self::pages() as $page) {
            if (! CmsPage::query()->where('slug', $page['slug'])->exists()) {
                CmsPage::create([...$page, 'sections' => SiteSections::clean($page['sections']), 'body' => '']);
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return list<array{slug: string, title: string, meta_description: string, menu_label: ?string, menu_order: ?int, published: bool, sections: list<array<string, mixed>>}>
     */
    public static function pages(): array
    {
        $start = ['label' => 'Start free trial', 'href' => '/start'];

        return [
            [
                'slug' => 'home', 'title' => 'Dr Business Flow — healthcare network software for South Africa', 'menu_label' => null, 'menu_order' => null, 'published' => true,
                'meta_description' => 'Run your clinic, practice, pharmacy or lab on one platform, connected to your patients. Hosted in South Africa.',
                'sections' => [
                    ['type' => 'hero', 'eyebrow' => 'Built for South African healthcare', 'heading' => 'Your clinic, the pharmacy and the lab — finally on one line.',
                        'text' => 'Dr Business Flow runs the front desk, consultations, prescriptions, billing and medical aid claims, and connects your patients through one app. Every practice gets its own database, hosted in South Africa.',
                        'image' => '/images/site/hero-network.svg', 'primary' => $start, 'secondary' => ['label' => 'See pricing', 'href' => '/pricing']],
                    ['type' => 'cards', 'heading' => 'One platform, four kinds of provider',
                        'items' => [
                            ['title' => 'Clinics', 'text' => 'Multi-doctor queues, triage, in-house pharmacy and lab, claims and owner dashboards.', 'href' => '/pages/for-clinics', 'image' => '/images/site/clinic.svg'],
                            ['title' => 'Independent doctors', 'text' => 'Your own practice, bookings, prescriptions, billing and video consults.', 'href' => '/pages/for-doctors', 'image' => '/images/site/doctor.svg'],
                            ['title' => 'Pharmacies', 'text' => 'Receive e-scripts, dispense against the signed script, deliver and keep the S5/S6 register.', 'href' => '/pages/for-pharmacies-and-labs', 'image' => '/images/site/pharmacy.svg'],
                            ['title' => 'Labs', 'text' => 'Orders, samples, verified results and release to the referring doctor.', 'href' => '/pages/for-pharmacies-and-labs', 'image' => '/images/site/lab.svg'],
                        ]],
                    ['type' => 'steps', 'heading' => 'How a visit flows',
                        'items' => [
                            ['title' => 'Book or walk in', 'text' => 'Online, by phone or at the kiosk. Patients get a ticket and wait anywhere.'],
                            ['title' => 'Check in and triage', 'text' => 'Medical aid checked, vitals captured and a triage colour suggested.'],
                            ['title' => 'See the doctor', 'text' => 'Notes, ICD-10 codes and prescriptions with safety checks and PIN signing.'],
                            ['title' => 'Medicine and results', 'text' => 'In-house pharmacy or the patient\'s chosen pharmacy; results released to the app.'],
                            ['title' => 'Pay and go', 'text' => 'Card, cash, EFT or pay link into the practice\'s own account; claims sent automatically.'],
                        ]],
                    ['type' => 'features', 'heading' => 'Everything a practice runs on',
                        'items' => [
                            ['title' => 'Front desk and live queue', 'text' => 'Search-first registration, kiosk check-in and a waiting-room display.', 'icon' => 'clipboard'],
                            ['title' => 'Safer prescribing', 'text' => 'Allergy, interaction and schedule checks before a script can be signed.', 'icon' => 'pill'],
                            ['title' => 'Medical aid claims', 'text' => 'Eligibility at check-in, claims from the consult, remittances matched.', 'icon' => 'shield'],
                            ['title' => 'Payments to your account', 'text' => 'PayFast, Paystack, Peach Payments or Yoco — the money goes straight to you.', 'icon' => 'card'],
                            ['title' => 'Your own website', 'text' => 'Every practice gets an editable website and patient portal on its own address.', 'icon' => 'globe'],
                            ['title' => 'Owner dashboards', 'text' => 'Takings, revenue by doctor and source, claims ageing and cash-ups.', 'icon' => 'chart'],
                        ]],
                    ['type' => 'split', 'heading' => 'Your data stays yours — and stays in South Africa', 'image' => '/images/site/secure-data.svg',
                        'text' => 'Each provider has a separate database. Patient records, scripts and money never mix with another practice.',
                        'bullets' => ['Hosted in South Africa', 'A separate database for every provider', 'Append-only audit trail of every sensitive action', 'POPIA export of everything held about a patient', 'Read-only, never deleted, if a subscription lapses']],
                    ['type' => 'faq', 'heading' => 'Questions practices ask',
                        'items' => [
                            ['question' => 'Do patient payments go through Dr Business Flow?', 'answer' => 'No. Patients pay your practice directly through your own merchant account. Dr Business Flow only charges your subscription and the add-ons you switch on.'],
                            ['question' => 'Where is our data hosted?', 'answer' => 'In South Africa, in AWS Cape Town, with a separate database for your practice.'],
                            ['question' => 'Can we bring our existing patients?', 'answer' => 'Yes. Patient lists can be imported from a spreadsheet; each patient confirms consent at their next visit.'],
                            ['question' => 'Is there a contract?', 'answer' => 'Start with a 30-day free trial. Packages are billed monthly or annually.'],
                            ['question' => 'Do we get our own website?', 'answer' => 'Yes. Every practice gets a website on its own address that you can edit, with a patient portal for bookings, results and invoices.'],
                        ]],
                    ['type' => 'cta', 'heading' => 'Start with a 30-day free trial', 'text' => 'Set up your practice in minutes. We verify your registrations before you appear in search.', 'primary' => $start, 'secondary' => ['label' => 'Talk to us', 'href' => '/pages/contact']],
                ],
            ],
            ...RolePages::all(),
            [
                'slug' => 'about', 'title' => 'About Dr Business Flow', 'menu_label' => 'About', 'menu_order' => 5, 'published' => true,
                'meta_description' => 'Why Dr Business Flow exists and how it is built.',
                'sections' => [
                    ['type' => 'hero', 'heading' => 'Built so care moves at the patient\'s pace, not the paperwork\'s', 'text' => 'Dr Business Flow connects clinics, independent doctors, pharmacies and labs so a patient\'s visit — from booking to medicine — runs on one line.', 'image' => '/images/site/hero-network.svg'],
                    ['type' => 'split', 'heading' => 'How we build it', 'image' => '/images/site/secure-data.svg', 'text' => 'Every practice is isolated, every sensitive action is audited and clinical rules are signed off by clinicians before they go live.',
                        'bullets' => ['South African hosting', 'Separate database per provider', 'Clinical, pharmacy and legal review of rules', 'Regular, tested releases']],
                ],
            ],
            [
                'slug' => 'contact', 'title' => 'Contact us', 'menu_label' => 'Contact', 'menu_order' => 6, 'published' => true,
                'meta_description' => 'Talk to the Dr Business Flow team.',
                'sections' => [
                    ['type' => 'hero', 'heading' => 'Talk to us', 'text' => 'Questions about packages, moving your patient records or a demo for your practice? Send us a message and we\'ll get back to you within one working day.'],
                    ['type' => 'contact', 'heading' => 'Get in touch', 'note' => 'Replace these details with your sales and support contacts in Admin → Website.'],
                ],
            ],
            [
                'slug' => 'privacy', 'title' => 'Privacy policy', 'menu_label' => null, 'menu_order' => null, 'published' => false,
                'meta_description' => 'How Dr Business Flow processes personal information.',
                'sections' => [['type' => 'richtext', 'html' => '<p><strong>Draft for legal review — publish only after approval.</strong></p><h2>Who we are</h2><p>Dr Business Flow provides software to healthcare providers. For patient records, each provider is the responsible party and Dr Business Flow is its operator under POPIA.</p><h2>What we process</h2><p>Account details of practice staff, subscription and billing details, and — on behalf of providers — patient information needed for care, billing and claims.</p><h2>Where it is stored</h2><p>In South Africa (AWS Cape Town), in a separate database for each provider.</p><h2>Your rights</h2><p>You may ask for access to, correction of or deletion of your personal information, subject to health-record retention laws. Contact the provider you visited, or us for account information.</p>']],
            ],
            [
                'slug' => 'terms', 'title' => 'Terms of service', 'menu_label' => null, 'menu_order' => null, 'published' => false,
                'meta_description' => 'Terms for using Dr Business Flow.',
                'sections' => [['type' => 'richtext', 'html' => '<p><strong>Draft for legal review — publish only after approval.</strong></p><p>These terms will set out subscriptions, trials, acceptable use, data processing, availability and liability.</p>']],
            ],
        ];
    }
}
