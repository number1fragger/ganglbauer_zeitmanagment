<?php

namespace App\Controller\Api;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\WorkRequest;
use App\Enum\JobStatus;
use App\Enum\Priority;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demodaten fuer die Werkstatt Ganglbauer Landtechnik.
 */
class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $meister = $this->makeUser($manager, 'meister@ganglbauer.at', 'Franz', 'Ganglbauer', 'admin1234', ['ROLE_ADMIN']);
        $kevin = $this->makeUser($manager, 'kevin@ganglbauer.at', 'Kevin', 'Lichtl', 'test1234');
        $resul = $this->makeUser($manager, 'resul@ganglbauer.at', 'Resul', 'Zajmi', 'test1234');
        $toni = $this->makeUser($manager, 'toni@ganglbauer.at', 'Toni', 'Berger', 'test1234');

        $now = new \DateTimeImmutable();

        $plan = [
            // [Titel, Kunde, Arbeiter, Prioritaet, geplante Minuten, bereits gearbeitet, Faelligkeit in Tagen]
            ['Hydraulikschlauch tauschen', 'Hofer Landwirtschaft', $kevin, Priority::Urgent, 120, 150, 0],
            ['Service Traktor Steyr 4110', 'Fam. Brunner', $kevin, Priority::Normal, 480, 210, 2],
            ['Maehwerk Messerwechsel', 'Lagerhaus Tulln', $resul, Priority::High, 180, 60, 1],
            ['Anhaenger Bremsen pruefen', 'Fam. Steiner', $resul, Priority::Normal, 240, 0, 3],
            ['Kabine Elektrik Fehlersuche', 'Gut Ebersdorf', $toni, Priority::High, 300, 120, 2],
            ['Winterdienststreuer montieren', 'Gemeinde Sitzendorf', $toni, Priority::Low, 150, 0, 5],
            ['Oelwechsel Hoflader', 'Hofer Landwirtschaft', $meister, Priority::Normal, 90, 0, 1],
        ];

        foreach ($plan as [$title, $customer, $worker, $priority, $planned, $worked, $dueInDays]) {
            $job = (new Job())
                ->setTitle($title)
                ->setCustomer($customer)
                ->setAssignee($worker)
                ->setPriority($priority)
                ->setPlannedMinutes($planned)
                ->setStartsAt($now->setTime(7, 0))
                ->setDueAt($now->modify(sprintf('+%d days', $dueInDays))->setTime(16, 0));

            if ($worked > 0) {
                $job->setStatus(JobStatus::InProgress);
                $start = $now->modify('-1 day')->setTime(8, 0);
                $entry = (new TimeEntry())
                    ->setUser($worker)
                    ->setJob($job)
                    ->setNote('Arbeit an der Maschine')
                    ->setStartedAt($start)
                    ->setEndedAt($start->modify(sprintf('+%d minutes', $worked)));
                $manager->persist($entry);
            }

            $manager->persist($job);
        }

        // Abgeschlossene Arbeiten der letzten Wochen – Grundlage fuer den Soll/Ist-Vergleich.
        $history = [
            ['Reifen wechseln Frontlader', $kevin, 120, 95, 12],
            ['Getriebeoel Case IH', $kevin, 240, 300, 9],
            ['Zapfwelle instandsetzen', $resul, 180, 170, 8],
            ['Beleuchtung Anhaenger', $resul, 60, 110, 5],
            ['Klimaanlage befuellen', $toni, 90, 85, 4],
            ['Pflug Scharwechsel', $toni, 150, 240, 2],
        ];

        foreach ($history as [$title, $worker, $planned, $actual, $daysAgo]) {
            $done = $now->modify(sprintf('-%d days', $daysAgo))->setTime(15, 0);

            $job = (new Job())
                ->setTitle($title)
                ->setCustomer('Werkstattauftrag')
                ->setAssignee($worker)
                ->setPriority(Priority::Normal)
                ->setPlannedMinutes($planned)
                ->setDueAt($done);

            // Wurde die Zeit ueberschritten, ist vorher verlaengert worden (F5).
            if ($actual > $planned) {
                $job->extendBy((int) round(($actual - $planned) / 2));
            }

            $job->complete($done);
            $manager->persist($job);

            $start = $done->modify(sprintf('-%d minutes', $actual));
            $entry = (new TimeEntry())
                ->setUser($worker)
                ->setJob($job)
                ->setStartedAt($start)
                ->setEndedAt($done);
            $manager->persist($entry);
        }

        // F7 – Toni meldet, dass er uebermorgen frueh wieder Arbeit braucht.
        $request = (new WorkRequest())
            ->setUser($toni)
            ->setNeededAt($now->modify('+2 days')->setTime(7, 0))
            ->setNote('Winterdienststreuer ist bis dahin fertig');
        $manager->persist($request);

        $manager->flush();
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(
        ObjectManager $manager,
        string $email,
        string $firstName,
        string $lastName,
        string $password,
        array $roles = [],
    ): User {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles($roles)
            ->setWeeklyHours(38.5);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $manager->persist($user);

        return $user;
    }
}
