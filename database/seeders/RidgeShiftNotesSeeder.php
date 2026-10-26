<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Person;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Fictional shift reports for the assigned Blue Ridge demo clients. Never runs in production. */
class RidgeShiftNotesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Shift notes demo data may only run locally or in testing.');
        }
        $organization = DB::table('organizations')->where('slug', 'blue-ridge-behavioral-health')->first();
        $author = DB::table('users')->where('email', 'dev.ridge.clinician@wayalong.test')->first();
        if (! $organization || ! $author) {
            throw new LogicException('Run DevelopmentSeeder and RidgeClinicianDashboardSeeder first.');
        }
        foreach (['UIUX DEMO Avery', 'UIUX DEMO Blake', 'UIUX DEMO Cameron'] as $name) {
            $personId = Person::withoutGlobalScopes()->where('organization_id', $organization->id)
                ->where('person_type', 'client')->get(['id', 'first_name', 'last_name'])
                ->first(fn (Person $person) => $person->first_name === $name && $person->last_name === 'Assigned Active')?->id;
            $enrollmentId = $personId ? DB::table('client_enrollments')->where('organization_id', $organization->id)
                ->where('person_id', $personId)->where('status', 'active')->value('id') : null;
            if (! $enrollmentId) {
                throw new LogicException('Missing assigned demo client '.$name.'. Run RidgeClinicianDashboardSeeder first.');
            }
            // Sentinel in summary identifies this demo record without overwriting manual work.
            $alreadySeeded = DB::table('client_shift_notes')->where('client_enrollment_id', $enrollmentId)
                ->where('author_id', $author->id)->where('shift', 'evening')->get(['summary'])
                ->contains(fn ($row) => rescue(fn () => str_contains(Crypt::decryptString($row->summary), 'DEMO SHIFT HANDOFF'), false));
            if ($alreadySeeded) {
                continue;
            }
            DB::table('client_shift_notes')->insert([
                'organization_id' => $organization->id,
                'client_enrollment_id' => $enrollmentId,
                'author_id' => $author->id,
                'shift_date' => today()->toDateString(), 'shift' => 'evening',
                'summary' => Crypt::encryptString('DEMO SHIFT HANDOFF: Routine evening activities completed.'),
                'observations' => Crypt::encryptString('Fictional demo observation; no real patient information.'),
                'significant_events' => null,
                'interventions' => Crypt::encryptString('Staff supported the scheduled evening routine.'),
                'handoff' => Crypt::encryptString('Review the current alerts and confirm tomorrow’s schedule.'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->command?->info('Fictional Blue Ridge shift notes seeded for Avery, Blake, and Cameron.');
    }
}
