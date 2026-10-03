<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support\Website;

use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\SitePage;

/**
 * Default website every new provider gets on its subdomain. Text uses
 * {name}, {phone}, {email}, {address} and {hours} so it follows the
 * provider's details automatically; everything can be edited.
 */
final class ProviderWebsite
{
    public static function seed(ProviderType $type): int
    {
        $created = 0;
        foreach (self::pages($type) as $page) {
            if (! SitePage::query()->where('slug', $page['slug'])->exists()) {
                SitePage::create([...$page, 'sections' => SiteSections::clean($page['sections'])]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return list<array{slug: string, title: string, meta_description: string, menu_label: string, menu_order: int, published: bool, sections: list<array<string, mixed>>}>
     */
    public static function pages(ProviderType $type): array
    {
        $book = ['label' => $type === ProviderType::Pharmacy ? 'Track my medicine' : ($type === ProviderType::Lab ? 'See my results' : 'Book or check my visit'), 'href' => '/portal'];
        $call = ['label' => 'Call {phone}', 'href' => 'tel:{phone}'];
        $contact = ['type' => 'contact', 'heading' => 'Find us', 'note' => 'Opening hours: {hours}'];

        [$intro, $image, $services, $steps, $faq] = match ($type) {
            ProviderType::Clinic => [
                '{name} is a family medical practice. Book online, check in when you arrive and follow your place in the queue on your phone.',
                '/images/site/clinic.svg',
                [['General consultations', 'For adults and children, by appointment or walk-in.', 'stethoscope'], ['Chronic care', 'Hypertension, diabetes, asthma and repeat scripts.', 'heart'], ['On-site pharmacy', 'Collect your medicine before you leave.', 'pill'], ['Lab tests', 'Samples taken here; results sent to your app.', 'flask'], ['Minor procedures', 'Stitches, dressings and small procedures with a quote first.', 'bandage'], ['Medical aid', 'We check your benefits at check-in and claim for you.', 'shield']],
                [['Book or walk in', 'Book online or come in — you get a ticket number.'], ['Check in', 'At reception or the kiosk with your cell number.'], ['Wait anywhere', 'Follow your place in the queue on your phone.'], ['See the doctor', 'Your script and results go to your app.']],
                [['Do you take walk-ins?', 'Yes. Booked patients are seen at their time; walk-ins join the queue by urgency and arrival.'], ['Which medical aids do you accept?', 'Most South African schemes. We check your benefits when you check in.'], ['What should I bring?', 'Your ID or passport, medical aid card and a list of medicines you take.']],
            ],
            ProviderType::IndependentDoctor => [
                '{name} offers personal, unhurried consultations — in the rooms or by video.',
                '/images/site/doctor.svg',
                [['Consultations', 'In person, by appointment.', 'stethoscope'], ['Video and phone consults', 'From home when a visit isn\'t needed.', 'video'], ['Chronic care', 'Ongoing care and repeat scripts.', 'heart'], ['Medical aid', 'Benefits checked and claims sent for you.', 'shield']],
                [['Book', 'Choose a time online.'], ['Consult', 'In the rooms or by video.'], ['Script and results', 'Sent to your app and pharmacy of choice.']],
                [['Can I see the doctor by video?', 'Yes, when it is clinically appropriate. Book a video consult online.'], ['Do you accept medical aid?', 'Yes. Benefits are checked before your consult.']],
            ],
            ProviderType::Pharmacy => [
                '{name} fills your scripts from any doctor on the network. Send your script, we prepare it and you collect or we deliver.',
                '/images/site/pharmacy.svg',
                [['Scripts', 'From your doctor straight to us — no paper needed.', 'pill'], ['Collection', 'Collect with your 4-digit code.', 'bag'], ['Delivery', 'To your door in our delivery area.', 'truck'], ['Chronic medicine', 'Repeats ready before you run out.', 'heart']],
                [['Choose us', 'Pick {name} when your doctor sends the script.'], ['We prepare it', 'We tell you if anything is out of stock.'], ['Collect or delivery', 'Show your code when you collect.']],
                [['Can someone collect for me?', 'Yes, with your collection code and their ID.'], ['What if an item is out of stock?', 'We supply the rest now and let you know when the owing item is ready.']],
            ],
            ProviderType::Lab => [
                '{name} runs pathology tests ordered by your doctor, with results sent to them and to your app once released.',
                '/images/site/lab.svg',
                [['Blood and urine tests', 'From routine checks to chronic monitoring.', 'flask'], ['Home collection', 'We come to you in our service area.', 'home'], ['Fast results', 'Sent electronically to your doctor.', 'clock'], ['Medical aid', 'We claim from your scheme.', 'shield']],
                [['Your doctor orders', 'The request comes to us electronically.'], ['Sample', 'At our branch or at home.'], ['Results', 'Verified, then released by your doctor.']],
                [['Do I need an appointment?', 'Walk in during opening hours, or book a home collection.'], ['Who sees my results?', 'Your doctor first; you see them once they are released to you.']],
            ],
        };

        return [
            [
                'slug' => 'home', 'title' => '{name}', 'meta_description' => $intro, 'menu_label' => 'Home', 'menu_order' => 0, 'published' => true,
                'sections' => [
                    ['type' => 'hero', 'eyebrow' => 'Welcome', 'heading' => 'Welcome to {name}', 'text' => $intro, 'image' => $image, 'primary' => $book, 'secondary' => $call],
                    ['type' => 'features', 'heading' => 'Our services', 'items' => array_map(fn ($s) => ['title' => $s[0], 'text' => $s[1], 'icon' => $s[2]], $services)],
                    ['type' => 'steps', 'heading' => 'How it works', 'items' => array_map(fn ($s) => ['title' => $s[0], 'text' => $s[1]], $steps)],
                    $contact,
                    ['type' => 'faq', 'heading' => 'Good to know', 'items' => array_map(fn ($f) => ['question' => $f[0], 'answer' => $f[1]], $faq)],
                ],
            ],
            [
                'slug' => 'services', 'title' => 'Services — {name}', 'meta_description' => 'Services at {name}.', 'menu_label' => 'Services', 'menu_order' => 1, 'published' => true,
                'sections' => [
                    ['type' => 'hero', 'heading' => 'Our services', 'text' => $intro, 'image' => $image, 'primary' => $book],
                    ['type' => 'features', 'heading' => 'What we offer', 'items' => array_map(fn ($s) => ['title' => $s[0], 'text' => $s[1], 'icon' => $s[2]], $services)],
                ],
            ],
            [
                'slug' => 'about', 'title' => 'About — {name}', 'meta_description' => 'About {name}.', 'menu_label' => 'About', 'menu_order' => 2, 'published' => true,
                'sections' => [
                    ['type' => 'split', 'heading' => 'About {name}', 'image' => $image, 'text' => 'Tell patients about your team, your experience and what makes your practice different. Edit this text in Settings → Website.',
                        'bullets' => ['Registered with the relevant professional council', 'Patient records kept securely in South Africa', 'Online bookings and results']],
                ],
            ],
            [
                'slug' => 'contact', 'title' => 'Contact — {name}', 'meta_description' => 'Contact {name}.', 'menu_label' => 'Contact', 'menu_order' => 3, 'published' => true,
                'sections' => [$contact, ['type' => 'cta', 'heading' => 'Need to see us?', 'text' => 'Book online or call us on {phone}.', 'primary' => $book]],
            ],
        ];
    }
}
