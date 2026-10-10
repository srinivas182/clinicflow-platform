<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support\Website;

/**
 * The four "Solutions" pages of the platform website: clinics, individual doctors, pharmacies & labs,
 * and patients. Feature-led and factual — every claim describes something the platform does today.
 * Edited pages are never overwritten (see the migration that refreshes unedited ones).
 */
final class RolePages
{
    private const START = ['label' => 'Start Free Trial', 'href' => '/start'];

    private const PRICING = ['label' => 'See Pricing', 'href' => '/pricing'];

    private const CONTACT = ['label' => 'Talk to Us', 'href' => '/pages/contact'];

    /**
     * @return list<array{slug: string, title: string, meta_description: string, menu_label: ?string, menu_order: ?int, published: bool, sections: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [self::clinics(), self::doctors(), self::pharmaciesAndLabs(), self::patients()];
    }

    /** @return array<string, mixed> */
    private static function security(string $heading = 'Your data stays yours — and stays in South Africa'): array
    {
        return ['type' => 'split', 'heading' => $heading, 'image' => '/images/site/secure-data.svg',
            'text' => 'Built for POPIA from the first line of code, not added afterwards.',
            'bullets' => [
                'Hosted in South Africa',
                'A separate database for every practice — never shared with another',
                'Patient consent recorded, and every access to a record in the audit trail',
                'Role-based access, two-step sign-in and automatic sign-out after inactivity',
                'Encrypted connections, encrypted secrets and daily backups',
            ]];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array{slug: string, title: string, meta_description: string, menu_label: ?string, menu_order: ?int, published: bool, sections: list<array<string, mixed>>}
     */
    private static function page(string $slug, string $title, string $menuLabel, int $order, string $meta, array $sections): array
    {
        return ['slug' => $slug, 'title' => $title, 'menu_label' => $menuLabel, 'menu_order' => $order, 'published' => true,
            'meta_description' => $meta, 'sections' => $sections];
    }

    /** @return array{slug: string, title: string, meta_description: string, menu_label: ?string, menu_order: ?int, published: bool, sections: list<array<string, mixed>>} */
    private static function clinics(): array
    {
        return self::page('for-clinics', 'Dr Business Flow for Clinics', 'Clinics', 1,
            'Run your whole clinic on one screen — front desk, triage, consultations, prescriptions, billing and reports.', [
                ['type' => 'hero', 'eyebrow' => 'For Clinics & Medical Centres', 'image' => '/images/site/clinic.svg',
                    'heading' => 'Run your whole clinic on one screen — from the front desk to the final invoice.',
                    'text' => 'Check patients in, triage, consult, prescribe, bill and follow up in one system your whole team can use from day one. No more paper files, double capturing or chasing the next patient.',
                    'primary' => self::START, 'secondary' => self::PRICING],
                ['type' => 'features', 'heading' => 'Everything your clinic runs on', 'items' => [
                    ['icon' => 'users', 'title' => 'Front desk & live queue', 'text' => 'Register and check patients in. Reception, nurses and doctors see the queue update live — no shouting down the passage.'],
                    ['icon' => 'activity', 'title' => 'Triage & vitals', 'text' => 'Nurses capture vitals and priority; the doctor sees them before calling the patient in.'],
                    ['icon' => 'stethoscope', 'title' => 'Consultations & AI notes', 'text' => 'Structured notes and diagnoses, with an optional AI scribe that drafts the note for the doctor to check and approve.'],
                    ['icon' => 'pill', 'title' => 'E-scripts & lab requests', 'text' => 'Send prescriptions to pharmacies and requests to labs on the network. Results come back into the patient\'s file.'],
                    ['icon' => 'receipt', 'title' => 'Billing & medical aid', 'text' => 'The invoice builds itself from the consult. Cash or medical aid, payment links by SMS or email.'],
                    ['icon' => 'chart', 'title' => 'Reports & branches', 'text' => 'Daily takings, visits and doctor activity, exported to Excel — per branch or across the whole group.'],
                ]],
                ['type' => 'split', 'heading' => 'A front desk that keeps the waiting room calm', 'image' => '/images/site/hero-network.svg',
                    'text' => 'Patients know where they stand, and your reception team stops answering "how much longer?".',
                    'bullets' => [
                        'Online booking from your own practice website',
                        'Self check-in on a kiosk tablet in the waiting room',
                        'A waiting-room screen that calls the next patient by ticket number',
                        'Patients follow their place in the queue on their own phone',
                        'SMS and email reminders, and recalls for follow-ups',
                    ]],
                ['type' => 'split', 'heading' => 'Doctors spend time with patients, not paperwork', 'image' => '/images/site/doctor.svg',
                    'text' => 'Everything the doctor needs is on one screen before the patient sits down.',
                    'bullets' => [
                        'History, allergies, vitals and results in one place',
                        'AI scribe drafts the consult note from the conversation — the doctor approves every word',
                        'Prescriptions checked and sent to the patient\'s chosen pharmacy',
                        'Video consults for follow-ups, and referrals to specialists on the network',
                    ]],
                ['type' => 'steps', 'heading' => 'Up and running in four steps', 'items' => [
                    ['title' => 'Sign up', 'text' => 'Choose a package — Clinic Free is free for one doctor — and tell us about your practice.'],
                    ['title' => 'Get verified', 'text' => 'We confirm your practice details (HPCSA and BHF numbers) to keep the network trusted.'],
                    ['title' => 'Invite your team', 'text' => 'Reception, nurses, doctors and billing each get the screens their role needs.'],
                    ['title' => 'See patients', 'text' => 'Your practice website, online booking and patient portal are ready from the first day.'],
                ]],
                self::security(),
                ['type' => 'faq', 'heading' => 'Questions clinics ask', 'items' => [
                    ['question' => 'Is there a free package?', 'answer' => 'Yes. Clinic Free covers one doctor and one branch at no cost. Paid packages add more doctors, branches and features, and include a 30-day trial.'],
                    ['question' => 'Do we need special hardware?', 'answer' => 'No. It runs in the browser on the computers, tablets and phones you already have. A tablet for self check-in and a TV for the waiting-room screen are optional.'],
                    ['question' => 'Can we run several branches?', 'answer' => 'Yes. Staff can work across branches, and owners see reports per branch or for the whole group.'],
                    ['question' => 'Do patients need to install an app?', 'answer' => 'No. The patient portal works in any phone browser; patients sign in with a code sent to their mobile number.'],
                    ['question' => 'How are SMS messages charged?', 'answer' => 'Each package includes a monthly allowance of SMS and email. Anything above that is charged from your practice wallet at the published rate.'],
                    ['question' => 'Who can see our patients\' records?', 'answer' => 'Only your staff, according to their role. Records are shared with another practice only when the patient consents.'],
                ]],
                ['type' => 'cta', 'heading' => 'Give your team a calmer day', 'text' => 'Start free — no card needed. Paid packages include a 30-day trial.', 'primary' => self::START, 'secondary' => self::CONTACT],
            ]);
    }

    /** @return array{slug: string, title: string, meta_description: string, menu_label: ?string, menu_order: ?int, published: bool, sections: list<array<string, mixed>>} */
    private static function doctors(): array
    {
        return self::page('for-doctors', 'Dr Business Flow for Individual Doctors', 'Individual Doctors', 2,
            'Your own practice, website and patients — bookings, notes, prescriptions, billing and video consults in one place.', [
                ['type' => 'hero', 'eyebrow' => 'For Individual Doctors', 'image' => '/images/site/doctor.svg',
                    'heading' => 'Your own practice, your own website, your own patients — without the admin.',
                    'text' => 'Bookings, consult notes, prescriptions, invoices and video consults in one place, with a professional practice website at your own address.',
                    'primary' => self::START, 'secondary' => self::PRICING],
                ['type' => 'features', 'heading' => 'A complete practice in your pocket', 'items' => [
                    ['icon' => 'calendar', 'title' => 'Online bookings', 'text' => 'Patients book from your website or portal; reminders go out automatically.'],
                    ['icon' => 'stethoscope', 'title' => 'Notes & AI scribe', 'text' => 'Structured consult notes, with an AI scribe that drafts them for you to approve.'],
                    ['icon' => 'pill', 'title' => 'E-scripts', 'text' => 'Send prescriptions straight to the patient\'s pharmacy on the network.'],
                    ['icon' => 'wallet', 'title' => 'Invoices & payments', 'text' => 'Invoice from the consult and send a payment link by SMS or email.'],
                    ['icon' => 'video', 'title' => 'Video consults', 'text' => 'Follow-ups and check-ins by secure video, booked and billed like any visit.'],
                    ['icon' => 'globe', 'title' => 'Your own website', 'text' => 'A practice website with your services, hours, map and booking — edit it yourself.'],
                ]],
                ['type' => 'split', 'heading' => 'Work from your rooms, the hospital or home', 'image' => '/images/site/patient-app.svg',
                    'text' => 'Everything is in the browser — nothing to install, nothing to back up.',
                    'bullets' => [
                        'Works on your laptop, tablet and phone',
                        'Patient history and results wherever you are',
                        'Video consults for follow-ups',
                        'Find and accept locum shifts on the network when you want extra work',
                    ]],
                ['type' => 'split', 'heading' => 'Get paid without chasing', 'image' => '/images/site/pharmacy.svg',
                    'text' => 'Billing happens as part of the consult, not at the end of the month.',
                    'bullets' => [
                        'The invoice is ready the moment the consult is done',
                        'Payment links by SMS or email, paid by card online',
                        'Medical aid details on every invoice',
                        'Prepaid packages and statements for regular patients',
                    ]],
                ['type' => 'steps', 'heading' => 'Start seeing patients this week', 'items' => [
                    ['title' => 'Sign up', 'text' => 'Doctor Free costs nothing; paid packages include a 30-day trial.'],
                    ['title' => 'Get verified', 'text' => 'We confirm your HPCSA and practice numbers.'],
                    ['title' => 'Set up your website', 'text' => 'Add your services, hours and photo — your booking page is live.'],
                    ['title' => 'See patients', 'text' => 'Consult, prescribe and invoice from one screen.'],
                ]],
                self::security(),
                ['type' => 'faq', 'heading' => 'Questions doctors ask', 'items' => [
                    ['question' => 'What does it cost?', 'answer' => 'Doctor Free is free. Paid packages add features and capacity; see Pricing for current prices.'],
                    ['question' => 'Do I get my own website?', 'answer' => 'Yes — a practice website at your own address on Dr Business Flow, with online booking built in. You can edit the pages yourself.'],
                    ['question' => 'Does the AI scribe write notes without me?', 'answer' => 'No. It drafts the note from the consultation; nothing is saved until you check and approve it.'],
                    ['question' => 'Can I add a receptionist later?', 'answer' => 'Yes. Invite staff at any time; each gets only the screens their role needs.'],
                    ['question' => 'Can patients pay online?', 'answer' => 'Yes. Send a payment link with the invoice; patients pay by card from their phone.'],
                ]],
                ['type' => 'cta', 'heading' => 'Spend your time on patients, not admin', 'text' => 'Start free — no card needed.', 'primary' => self::START, 'secondary' => self::CONTACT],
            ]);
    }

    /** @return array{slug: string, title: string, meta_description: string, menu_label: ?string, menu_order: ?int, published: bool, sections: list<array<string, mixed>>} */
    private static function pharmaciesAndLabs(): array
    {
        return self::page('for-pharmacies-and-labs', 'Dr Business Flow for Pharmacies & Labs', 'Pharmacies & Labs', 3,
            'Receive e-scripts and lab orders straight from the doctors who write them — dispense, deliver, test and report on one network.', [
                ['type' => 'hero', 'eyebrow' => 'For Pharmacies & Labs', 'image' => '/images/site/pharmacy.svg',
                    'heading' => 'Receive scripts and lab orders straight from the doctors who write them.',
                    'text' => 'Join the Dr Business Flow network: prescriptions and lab requests arrive digitally, signed and complete — no faxes, no illegible handwriting, no phone calls to check.',
                    'primary' => self::START, 'secondary' => self::PRICING],
                ['type' => 'features', 'heading' => 'Built for the counter and the bench', 'items' => [
                    ['icon' => 'file', 'title' => 'E-scripts in, no paper', 'text' => 'Prescriptions from network doctors arrive ready to dispense.'],
                    ['icon' => 'check', 'title' => 'Dispense against the signed script', 'text' => 'Every item checked against what the doctor signed.'],
                    ['icon' => 'clipboard', 'title' => 'S5/S6 register', 'text' => 'Scheduled medicines recorded as you dispense, ready for inspection.'],
                    ['icon' => 'truck', 'title' => 'Delivery & dispatch', 'text' => 'Deliver to the patient\'s door; patients track their medicine on their phone.'],
                    ['icon' => 'flask', 'title' => 'Lab orders & samples', 'text' => 'Requests arrive with the patient\'s details; track each sample from collection to result.'],
                    ['icon' => 'shield', 'title' => 'Verified results', 'text' => 'Results are verified before release and go straight back to the referring doctor.'],
                ]],
                ['type' => 'split', 'heading' => 'For pharmacies', 'image' => '/images/site/pharmacy.svg',
                    'text' => 'More scripts from the doctors around you, handled faster and safer.',
                    'bullets' => [
                        'Patients choose your pharmacy when the doctor prescribes',
                        'Dispense, label and record in one flow',
                        'Delivery with courier partners, tracked by the patient',
                        'Stock you list is visible to doctors on the network',
                    ]],
                ['type' => 'split', 'heading' => 'For labs', 'image' => '/images/site/lab.svg',
                    'text' => 'Orders arrive complete, and results reach the doctor the moment they are released.',
                    'bullets' => [
                        'Electronic requests from network doctors — no transcription',
                        'Sample tracking from collection to result',
                        'Verification before any result is released',
                        'Results explained to patients in plain language in their portal',
                    ]],
                ['type' => 'steps', 'heading' => 'Join the network in four steps', 'items' => [
                    ['title' => 'Sign up', 'text' => 'Pharmacy Free and Lab Free cost nothing to start.'],
                    ['title' => 'Get verified', 'text' => 'We confirm your registration details.'],
                    ['title' => 'Invite your team', 'text' => 'Pharmacists, technicians, dispatch and billing each get their own screens.'],
                    ['title' => 'Receive work', 'text' => 'Scripts and orders from network doctors start arriving.'],
                ]],
                self::security('Patient data handled the way the law expects'),
                ['type' => 'faq', 'heading' => 'Questions pharmacies and labs ask', 'items' => [
                    ['question' => 'How do we receive scripts and orders?', 'answer' => 'Doctors on the network send them electronically; they appear in your queue with the patient\'s details and the doctor\'s signature.'],
                    ['question' => 'Is there a free package?', 'answer' => 'Yes — Pharmacy Free and Lab Free. Paid packages add more branches, storage and messaging.'],
                    ['question' => 'Can patients track their medicine?', 'answer' => 'Yes. Patients see the status of their order — dispensed, out for delivery, delivered — on their phone.'],
                    ['question' => 'Who sees the results we release?', 'answer' => 'The referring doctor and the patient. Anyone else only with the patient\'s consent.'],
                    ['question' => 'Can we keep using our own systems?', 'answer' => 'Labs can connect through the network API for orders and results. Talk to us about your set-up.'],
                ]],
                ['type' => 'cta', 'heading' => 'Be where the doctors are', 'text' => 'Join the network free — no card needed.', 'primary' => self::START, 'secondary' => self::CONTACT],
            ]);
    }

    /** @return array{slug: string, title: string, meta_description: string, menu_label: ?string, menu_order: ?int, published: bool, sections: list<array<string, mixed>>} */
    private static function patients(): array
    {
        $find = ['label' => 'Find Care', 'href' => '/find-care'];

        return self::page('for-patients', 'Dr Business Flow for Patients', 'Patients', 4,
            'Book, check in, follow your queue, see your results explained and pay your account — from your phone, no app needed.', [
                ['type' => 'hero', 'eyebrow' => 'For Patients', 'image' => '/images/site/patient-app.svg',
                    'heading' => 'Your health, your practice, one simple portal.',
                    'text' => 'Book appointments, follow your place in the queue, read your results in plain language and pay your account — from your phone, with nothing to install.',
                    'primary' => $find, 'secondary' => ['label' => 'How It Works', 'href' => '/pages/for-patients#how-it-works']],
                ['type' => 'features', 'heading' => 'Everything you need from your practice', 'items' => [
                    ['icon' => 'calendar', 'title' => 'Book online', 'text' => 'Choose a time that suits you and get a reminder before your appointment.'],
                    ['icon' => 'clock', 'title' => 'Your place in the queue', 'text' => 'Wait in the car or the coffee shop — your phone shows when you are next.'],
                    ['icon' => 'flask', 'title' => 'Results explained', 'text' => 'See your lab results with a plain-language explanation, reviewed by your doctor.'],
                    ['icon' => 'wallet', 'title' => 'Pay your account', 'text' => 'See your invoices and pay by card from your phone.'],
                    ['icon' => 'message', 'title' => 'Chat & video', 'text' => 'Message your practice and join video consults when your doctor offers them.'],
                    ['icon' => 'truck', 'title' => 'Track your medicine', 'text' => 'Follow your prescription from the pharmacy to your door.'],
                ]],
                ['type' => 'split', 'heading' => 'Less waiting in the waiting room', 'image' => '/images/site/clinic.svg',
                    'text' => 'Your phone tells you when it is your turn, so you can wait wherever you are comfortable.',
                    'bullets' => [
                        'Check in from your phone or on the tablet at reception',
                        'See your ticket number and how many people are ahead of you',
                        'The waiting-room screen calls you when the doctor is ready',
                        'Get a reminder before your appointment, so you never miss it',
                    ]],
                ['type' => 'split', 'heading' => 'You decide who sees your records', 'image' => '/images/site/secure-data.svg',
                    'text' => 'Your records stay with your practice unless you choose to share them.',
                    'bullets' => [
                        'Share your history with another doctor only when you consent',
                        'Manage appointments for your children and family from one sign-in',
                        'Sign in with a code sent to your mobile — no password to remember',
                    ]],
                ['type' => 'steps', 'heading' => 'How it works', 'items' => [
                    ['title' => 'Find your practice', 'text' => 'Search Find Care, or use the link from your practice.'],
                    ['title' => 'Sign in with your mobile', 'text' => 'We send a one-time code by SMS.'],
                    ['title' => 'Book or check in', 'text' => 'Choose a time, or check in when you arrive.'],
                    ['title' => 'Results and payments', 'text' => 'Read your results and pay your account in the same place.'],
                ]],
                ['type' => 'faq', 'heading' => 'Questions patients ask', 'items' => [
                    ['question' => 'Does it cost anything?', 'answer' => 'No. The patient portal is free for patients.'],
                    ['question' => 'Do I need to download an app?', 'answer' => 'No. It works in your phone\'s browser.'],
                    ['question' => 'How do I sign in?', 'answer' => 'With your mobile number: we send you a one-time code by SMS each time.'],
                    ['question' => 'Can I book for my children?', 'answer' => 'Yes. Family members can be linked to your profile and you can switch between them.'],
                    ['question' => 'What if my practice is not on Dr Business Flow?', 'answer' => 'Search Find Care for practices near you, or tell your practice about us.'],
                ]],
                ['type' => 'cta', 'heading' => 'Find a practice near you', 'text' => 'Verified clinics, doctors, pharmacies and labs on the network.', 'primary' => $find],
            ]);
    }
}
