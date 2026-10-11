<?php

declare(strict_types=1);

namespace App\Domains\Translation\Support;

use App\Domains\Translation\Models\Translation;

/**
 * The patient portal's screen texts. Each is translatable with the same review process as messages;
 * texts on the results page are medical wording (a clinical reviewer must also approve them).
 * The portal shows approved translations only — English for everything else.
 */
final class PortalText
{
    /** @var array<string, string> */
    public const STRINGS = [
        'portal.care.my_care' => 'My care',
        'portal.care.save' => 'Save',
        'portal.care.nothing_yet' => 'Nothing yet.',
        'portal.care.all_checks_in_the_healthy_range' => 'All checks in the healthy range.',
        'portal.care.messages_on_whatsapp' => 'Messages on WhatsApp',
        'portal.care.what_this_practice_may_see_from_your_net' => 'What this practice may see from your network history',
        'portal.care.who_discussed_your_care_and_what_was_sha' => 'Who discussed your care and what was shared',
        'portal.care.referrals' => 'Referrals',
        'portal.care.notes_from_your_clinicians' => 'Notes from your clinicians',
        'portal.care.immunisations' => 'Immunisations',
        'portal.care.your_wellness_screenings_private_to_you' => 'Your wellness screenings (private to you)',
        'portal.care.connected_systems' => 'Connected systems',
        'portal.home.language_for_your_messages' => 'Language for your messages',
        'portal.home.cancel' => 'Cancel',
        'portal.home.book_for' => 'Book for',
        'portal.home.no_open_times_on_this_day' => 'No open times on this day.',
        'portal.home.no_results_yet' => 'No results yet.',
        'portal.home.paid' => 'Paid',
        'portal.home.sign_out' => 'Sign out',
        'portal.home.my_health' => 'My health',
        'portal.home.today_s_visit' => 'Today\'s visit',
        'portal.home.appointments' => 'Appointments',
        'portal.home.booking_date' => 'Booking date',
        'portal.home.results' => 'Results',
        'portal.home.invoices_and_scripts' => 'Invoices and scripts',
        'portal.login.enter_the_cell_number_the_practice_has_f' => 'Enter the cell number the practice has for you. We\'ll SMS you a one-time code.',
        'portal.login.send_code' => 'Send code',
        'portal.login.sign_in' => 'Sign in',
        'portal.login.your_health_records' => 'Your health records',
        'portal.login.cell_number' => 'Cell number',
        'portal.online.online_consults' => 'Online consults',
        'portal.online.no_free_times_on_this_day' => 'No free times on this day.',
        'portal.online.none_yet' => 'None yet.',
        'portal.online.pay_now' => 'Pay now',
        'portal.online.cancel' => 'Cancel',
        'portal.online.book' => 'Book',
        'portal.online.patient' => 'Patient',
        'portal.online.doctor' => 'Doctor',
        'portal.online.date' => 'Date',
        'portal.online.your_online_consults' => 'Your online consults',
        'portal.online.chats' => 'Chats',
        'portal.pharmacies.compare_pharmacies' => 'Compare pharmacies',
        'portal.pharmacies.for_your_latest_script_prices_are_estima' => 'For your latest script. Prices are estimates — medicine prices are regulated, so differences are mostly dispensing fees. Confirm at the pharmacy.',
        'portal.pharmacies.you_have_no_current_script' => 'You have no current script.',
        'portal.pharmacies.stock_not_published' => 'stock not published',
        'portal.pharmacies.your_script' => 'Your script',
        'portal.pharmacies.pharmacies' => 'Pharmacies',
        'portal.practices.practices_linked_to_you' => 'Practices linked to you',
        'portal.practices.these_practices_can_find_your_records_on' => 'These practices can find your records on the Dr Business Flow network and send your e-scripts. Remove one at any time.',
        'portal.practices.no_practices_are_linked' => 'No practices are linked.',
        'portal.practices.remove' => 'Remove',
        'portal.practices.linked_practices' => 'Linked practices',
        'portal.results.your_results' => 'Your results',
        'portal.results.your_doctor_reviews_results_before_you_s' => 'Your doctor reviews results before you see them.',
        'portal.results.no_lab_tests_yet' => 'No lab tests yet.',
        'portal.results.book_a_follow_up' => 'Book a follow-up',
        'portal.results.results_pdf' => 'Results PDF',
        'portal.results.lab_s_report' => 'Lab\'s report',
        'portal.results.request_my_results' => 'Request my results',
        'portal.results.you_asked_your_doctor_for_these_results' => 'You asked your doctor for these results.',
        'portal.results.results' => 'Results',
        'portal.verify.if_this_number_is_registered_with_the_pr' => 'If this number is registered with the practice, a 6-digit code is on its way.',
        'portal.verify.continue' => 'Continue',
        'portal.verify.use_another_number' => 'Use another number',
        'portal.verify.enter_code' => 'Enter code',
        'portal.verify.enter_your_code' => 'Enter your code',
        'portal.verify.code' => 'Code',
    ];

    public static function medical(string $key): bool
    {
        return str_starts_with($key, 'portal.results.');
    }

    /**
     * The portal's texts in a language: approved, current translations, English for the rest.
     *
     * @return array<string, string>
     */
    public static function dictionary(string $language): array
    {
        $texts = self::STRINGS;
        if ($language === 'en') {
            return $texts;
        }
        $rows = Translation::query()->where('language', $language)->where('unit', 'like', 'ui:portal.%')->get();
        foreach ($rows as $t) {
            $key = substr($t->unit, 3);
            if (isset($texts[$key]) && $t->isLive($texts[$key])) {
                $texts[$key] = (string) $t->text;
            }
        }

        return $texts;
    }
}
