<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DEMO reference data for development and testing only: a small ICD-10 set
 * and a demo medicine catalogue with a handful of well-known interactions.
 * Production uses the full SA ICD-10 MIT and the licensed drug database.
 */
class ClinicalReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $icd = [
            ['J06.9', 'Acute upper respiratory infection, unspecified'], ['J20.9', 'Acute bronchitis, unspecified'],
            ['J45.9', 'Asthma, unspecified'], ['J02.9', 'Acute pharyngitis, unspecified'], ['J01.9', 'Acute sinusitis, unspecified'],
            ['I10', 'Essential (primary) hypertension'], ['E11.9', 'Type 2 diabetes mellitus without complications'],
            ['E78.5', 'Hyperlipidaemia, unspecified'], ['N39.0', 'Urinary tract infection, site not specified'],
            ['K21.9', 'Gastro-oesophageal reflux disease without oesophagitis'], ['A09.9', 'Gastroenteritis and colitis of unspecified origin'],
            ['M54.5', 'Low back pain'], ['R51', 'Headache'], ['R50.9', 'Fever, unspecified'], ['L30.9', 'Dermatitis, unspecified'],
            ['H66.9', 'Otitis media, unspecified'], ['B34.9', 'Viral infection, unspecified'], ['F41.1', 'Generalised anxiety disorder'],
            ['F32.9', 'Depressive episode, unspecified'], ['Z00.0', 'General medical examination'], ['Z76.0', 'Issue of repeat prescription'],
            ['T78.4', 'Allergy, unspecified'], ['R10.4', 'Other and unspecified abdominal pain'], ['S93.4', 'Sprain and strain of ankle'],
        ];
        foreach ($icd as [$code, $description]) {
            DB::table('icd10_codes')->updateOrInsert(['code' => $code], ['description' => $description, 'valid_primary' => ! str_starts_with($code, 'Z76')]);
        }

        // [nappi, name, strength, form, schedule, ingredients, allergy classes, default dose, price cents]
        $medicines = [
            ['700001', 'Amoxicillin', '500 mg', 'capsule', 'S4', ['amoxicillin'], ['penicillin', 'beta-lactam'], '1 capsule three times daily for 5 days', 6500],
            ['700002', 'Azithromycin', '500 mg', 'tablet', 'S4', ['azithromycin'], ['macrolide'], '1 tablet daily for 3 days', 9800],
            ['700003', 'Paracetamol', '500 mg', 'tablet', 'S0', ['paracetamol'], [], '2 tablets every 6 hours as needed', 1800],
            ['700004', 'Ibuprofen', '400 mg', 'tablet', 'S2', ['ibuprofen'], ['nsaid'], '1 tablet three times daily with food', 2400],
            ['700005', 'Salbutamol inhaler', '100 mcg/dose', 'inhaler', 'S2', ['salbutamol'], [], '2 puffs as needed', 7500],
            ['700006', 'Amlodipine', '5 mg', 'tablet', 'S3', ['amlodipine'], [], '1 tablet daily', 5200],
            ['700007', 'Metformin', '500 mg', 'tablet', 'S3', ['metformin'], [], '1 tablet twice daily with meals', 3900],
            ['700008', 'Warfarin', '5 mg', 'tablet', 'S4', ['warfarin'], [], 'As directed by INR clinic', 6100],
            ['700009', 'Tramadol', '50 mg', 'capsule', 'S6', ['tramadol'], ['opioid'], '1 capsule every 6 hours as needed', 8800],
            ['700010', 'Diclofenac', '50 mg', 'tablet', 'S3', ['diclofenac'], ['nsaid'], '1 tablet twice daily with food', 3100],
            ['700011', 'Cefalexin', '500 mg', 'capsule', 'S4', ['cefalexin'], ['cephalosporin', 'beta-lactam'], '1 capsule four times daily', 8200],
            ['700012', 'Nitrofurantoin', '100 mg', 'capsule', 'S4', ['nitrofurantoin'], [], '1 capsule twice daily for 5 days', 7300],
            ['700013', 'Omeprazole', '20 mg', 'capsule', 'S2', ['omeprazole'], [], '1 capsule daily before breakfast', 4200],
            ['700014', 'Fluoxetine', '20 mg', 'capsule', 'S5', ['fluoxetine'], [], '1 capsule daily', 6900],
        ];
        foreach ($medicines as [$nappi, $name, $strength, $form, $schedule, $ingredients, $classes, $dose, $price]) {
            DB::table('medicines')->updateOrInsert(['nappi_code' => $nappi], [
                'name' => $name, 'strength' => $strength, 'form' => $form, 'schedule' => $schedule,
                'ingredients' => json_encode($ingredients), 'allergy_classes' => json_encode($classes), 'default_dose' => $dose, 'price_cents' => $price,
            ]);
        }

        $interactions = [
            ['ibuprofen', 'warfarin', 'major', 'NSAIDs with warfarin raise the risk of serious bleeding.'],
            ['diclofenac', 'warfarin', 'major', 'NSAIDs with warfarin raise the risk of serious bleeding.'],
            ['amlodipine', 'ibuprofen', 'moderate', 'NSAIDs can reduce the blood-pressure-lowering effect of amlodipine.'],
            ['azithromycin', 'warfarin', 'moderate', 'Azithromycin may increase the effect of warfarin; monitor INR.'],
            ['fluoxetine', 'tramadol', 'major', 'Risk of serotonin syndrome and seizures.'],
        ];
        foreach ($interactions as [$a, $b, $severity, $message]) {
            [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
            DB::table('drug_interactions')->updateOrInsert(['ingredient_a' => $x, 'ingredient_b' => $y], ['severity' => $severity, 'message' => $message]);
        }
    }
}
