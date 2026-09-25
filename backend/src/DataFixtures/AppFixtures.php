<?php

namespace App\DataFixtures;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\WorkRequest;
use App\Enum\JobStatus;
use App\Enum\Priority;
use App\Enum\UserRole;
use App\Service\WorkloadCalculator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demodaten fuer die Werkstatt Ganglbauer Landtechnik.
 *
 * Zugaenge:
 *   meister@ganglbauer.at      / admin1234  (Chef)
 *   vorarbeiter@ganglbauer.at  / test1234   (Vorarbeiter)
 *   kevin@, resul@, toni@...   / test1234   (Arbeiter)
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly WorkloadCalculator $workload,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $meister = $this->makeUser($manager, 'meister@ganglbauer.at', 'Franz', 'Ganglbauer', 'admin1234', UserRole::Admin);
        $martin = $this->makeUser($manager, 'vorarbeiter@ganglbauer.at', 'Martin', 'Pichler', 'test1234', UserRole::Foreman);
        $kevin = $this->makeUser($manager, 'kevin@ganglbauer.at', 'Kevin', 'Lichtl', 'test1234');
        $resul = $this->makeUser($manager, 'resul@ganglbauer.at', 'Resul', 'Zajmi', 'test1234');
        $toni = $this->makeUser($manager, 'toni@ganglbauer.at', 'Toni', 'Berger', 'test1234');

        $now = new \DateTimeImmutable();

        // Der Plan beginnt am letzten Werktag, damit schon Zeit erfasst sein kann.
        $planStart = $this->previousWorkday($now)->setTime(WorkloadCalculator::DAY_START_HOUR, 0);

        $plan = [
            // Arbeiter => [[Titel, Kunde, Prioritaet, geplante Minuten, bereits gearbeitet], ...]
            // Die Arbeiten eines Arbeiters werden lueckenlos hintereinander eingeplant.
            [$kevin, [
                ['Hydraulikschlauch tauschen', 'Hofer Landwirtschaft', Priority::Urgent, 120, 150],
                ['Service Traktor Steyr 4110', 'Fam. Brunner', Priority::Normal, 480, 210],
                ['Frontlader Zylinder abdichten', 'Gut Ebersdorf', Priority::High, 360, 0],
            ]],
            [$resul, [
                ['Maehwerk Messerwechsel', 'Lagerhaus Tulln', Priority::High, 180, 60],
                ['Anhaenger Bremsen pruefen', 'Fam. Steiner', Priority::Normal, 240, 0],
            ]],
            [$toni, [
                ['Kabine Elektrik Fehlersuche', 'Gut Ebersdorf', Priority::High, 300, 120],
                ['Winterdienststreuer montieren', 'Gemeinde Sitzendorf', Priority::Low, 900, 0],
                ['Getriebe Fendt 312 zerlegen', 'Fam. Wimmer', Priority::Normal, 960, 0],
            ]],
            [$martin, [
                ['Oelwechsel Hoflader', 'Hofer Landwirtschaft', Priority::Normal, 90, 0],
                ['Pickerl-Ueberpruefung §57a', 'Fam. Brunner', Priority::Normal, 120, 0],
            ]],
        ];

        foreach ($plan as [$worker, $jobs]) {
            $cursor = $planStart;

            foreach ($jobs as [$title, $customer, $priority, $planned, $worked]) {
                $blocks = $this->workload->splitIntoWorkingBlocks($worker, $cursor, $planned);
                $start = $blocks[0]['start'];
                $end = $blocks[\count($blocks) - 1]['end'];

                $job = (new Job())
                    ->setTitle($title)
                    ->setCustomer($customer)
                    ->setAssignee($worker)
                    ->setPriority($priority)
                    ->setPlannedMinutes($planned)
                    ->setStartsAt($start)
                    ->setDueAt($end);

                if ($worked > 0) {
                    $job->setStatus(JobStatus::InProgress);
                    $entryStart = min($start, $now->modify(sprintf('-%d minutes', $worked + 30)));
                    $manager->persist((new TimeEntry())
                        ->setUser($worker)
                        ->setJob($job)
                        ->setNote('Arbeit an der Maschine')
                        ->setStartedAt($entryStart)
                        ->setEndedAt($entryStart->modify(sprintf('+%d minutes', $worked))));
                }

                $manager->persist($job);
                $cursor = $end;
            }
        }

        // Noch nicht eingeplant – taucht im Kalender unter "Ungeplant" auf.
        $manager->persist((new Job())
            ->setTitle('Reifen wechseln Hoftrac')
            ->setCustomer('Fam. Leitner')
            ->setPriority(Priority::Normal)
            ->setPlannedMinutes(90));

        // Abgeschlossene Arbeiten der letzten Wochen – Grundlage fuer den Soll/Ist-Vergleich.
        $history = [
            ['Reifen wechseln Frontlader', $kevin, 120, 95, 12],
            ['Getriebeoel Case IH', $kevin, 240, 300, 9],
            ['Zapfwelle instandsetzen', $resul, 180, 170, 8],
            ['Beleuchtung Anhaenger', $resul, 60, 110, 5],
            ['Klimaanlage befuellen', $toni, 90, 85, 4],
            ['Pflug Scharwechsel', $toni, 150, 240, 2],
            ['Batterie tauschen Hoflader', $martin, 45, 50, 6],
        ];

        foreach ($history as [$title, $worker, $planned, $actual, $daysAgo]) {
            $done = $now->modify(sprintf('-%d days', $daysAgo))->setTime(14, 0);
            $start = $done->modify(sprintf('-%d minutes', $actual));

            $job = (new Job())
                ->setTitle($title)
                ->setCustomer('Werkstattauftrag')
                ->setAssignee($worker)
                ->setPriority(Priority::Normal)
                ->setPlannedMinutes($planned)
                ->setStartsAt($start)
                ->setDueAt($done);

            // Wurde die Zeit ueberschritten, ist vorher verlaengert worden (F5).
            if ($actual > $planned) {
                $job->extendBy((int) round(($actual - $planned) / 2));
            }

            $job->complete($done);
            $manager->persist($job);

            $manager->persist((new TimeEntry())
                ->setUser($worker)
                ->setJob($job)
                ->setStartedAt($start)
                ->setEndedAt($done));
        }

        // F7 – Resul meldet, dass er uebermorgen frueh wieder Arbeit braucht.
        $manager->persist((new WorkRequest())
            ->setUser($resul)
            ->setNeededAt($now->modify('+2 days')->setTime(7, 0))
            ->setNote('Anhaenger ist bis dahin fertig'));

        $manager->flush();
    }

    private function previousWorkday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $cursor = $day->modify('-1 day');

        while ((int) $cursor->format('N') >= 6) {
            $cursor = $cursor->modify('-1 day');
        }

        return $cursor;
    }

    private function makeUser(
        ObjectManager $manager,
        string $email,
        string $firstName,
        string $lastName,
        string $password,
        UserRole $role = UserRole::Worker,
    ): User {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRole($role)
            ->setWeeklyHours(38.5);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $manager->persist($user);

        return $user;
    }
}
