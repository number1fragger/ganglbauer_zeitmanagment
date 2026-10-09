<?php

namespace App\DataFixtures;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\WorkRequest;
use App\Enum\Priority;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demodaten passend zum Figma-Entwurf. Alle Termine haengen an der
 * laufenden Woche, damit der Kalender immer etwas zeigt.
 *
 * Passwort fuer alle Demo-Konten: werkstatt
 */
final class AppFixtures extends Fixture
{
    public const PASSWORD = 'werkstatt';

    /** [Tag ab Montag, Beginn, Stunden geplant, Stunden tatsaechlich, Arbeiter, Titel, Kunde, Prioritaet] */
    private const CURRENT_WEEK = [
        [0, '07:00', 2.5, 2.0, 'a.huber', 'Bremsen hinten', 'Fam. Wagner', Priority::High],
        [0, '09:30', 2.0, 2.0, 'a.huber', 'Service 60.000 km', 'M. Berger', Priority::Medium],
        [0, '12:00', 3.0, 4.0, 'a.huber', 'Kupplung tauschen', 'Fa. Leitner', Priority::High],
        [0, '07:00', 1.5, 1.5, 'b.steiner', 'Reifenwechsel', 'S. Pichler', Priority::Low],
        [0, '08:30', 2.0, 3.5, 'b.steiner', 'Pickerl §57a', 'R. Hofer', Priority::Medium],
        [0, '07:00', 4.0, 4.0, 'c.mayr', 'Motorschaden', 'T. Novak', Priority::High],
        [0, '11:30', 1.5, 1.0, 'c.mayr', 'Ölwechsel', 'A. Maier', Priority::Low],
        [0, '07:30', 2.0, 2.0, 'd.gruber', 'Auspuff schweißen', 'K. Sturm', Priority::Low],
        [0, '10:00', 2.5, 2.5, 'd.gruber', 'Lichtmaschine', 'Fa. Ebner', Priority::High],
        [1, '07:00', 3.0, 3.0, 'a.huber', 'Getriebe prüfen', 'Hofer Landwirtschaft', Priority::Medium],
        [1, '07:00', 6.0, 6.5, 'c.mayr', 'Motor Zusammenbau', 'T. Novak', Priority::High],
        [1, '10:30', 2.0, 1.5, 'd.gruber', 'Klimaservice', 'Fam. Brunner', Priority::Low],
        [1, '13:00', 2.5, 2.5, 'd.gruber', 'Stoßdämpfer', 'L. Winkler', Priority::Medium],
        [2, '07:00', 7.0, 7.0, 'a.huber', 'Karosserie', 'Gut Ebersdorf', Priority::Medium],
        [2, '07:00', 3.0, 3.0, 'c.mayr', 'Pickerl §57a', 'Lagerhaus Tulln', Priority::Medium],
        [2, '10:30', 2.0, 2.0, 'd.gruber', 'Batterie', 'Gemeinde Sitzendorf', Priority::Low],
        [3, '07:00', 3.0, 3.0, 'c.mayr', 'Endkontrolle', 'T. Novak', Priority::High],
        [3, '08:00', 2.0, 2.0, 'd.gruber', 'Zahnriemen', 'Fa. Ebner', Priority::High],
        [4, '08:00', 1.5, 1.5, 'a.huber', 'Reifen', 'S. Pichler', Priority::Low],
        [7, '07:00', 2.0, 2.0, 'a.huber', 'Inspektion', 'M. Berger', Priority::Medium],
        [7, '07:00', 2.5, 2.5, 'b.steiner', 'Bremsen vorne', 'L. Winkler', Priority::High],
        [7, '09:30', 1.5, 1.5, 'c.mayr', 'Ölwechsel', 'Fam. Wagner', Priority::Low],
        [8, '07:00', 4.0, 4.0, 'c.mayr', 'Achsvermessung', 'Fa. Leitner', Priority::Medium],
        [8, '07:00', 3.0, 3.0, 'd.gruber', 'Service', 'K. Sturm', Priority::Medium],
    ];

    /** Titel fuer die erledigten Arbeiten der Vorwochen (Soll/Ist-Auswertung). */
    private const HISTORY_TITLES = [
        'Hydraulikschlauch tauschen', 'Service Traktor', 'Mähwerk Messerwechsel', 'Anhänger Bremsen prüfen',
        'Kabine Elektrik', 'Ölwechsel', 'Zapfwelle instandsetzen', 'Beleuchtung Anhänger',
        'Klimaanlage befüllen', 'Pflug Scharwechsel', 'Reifenwechsel', 'Pickerl §57a',
    ];

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $users = [
            'p.hofer' => $this->user($manager, 'p.hofer', 'Peter', 'Hofer', UserRole::Chef),
            'a.huber' => $this->user($manager, 'a.huber', 'Anton', 'Huber', UserRole::Worker),
            'b.steiner' => $this->user($manager, 'b.steiner', 'Bernd', 'Steiner', UserRole::Worker),
            'c.mayr' => $this->user($manager, 'c.mayr', 'Clara', 'Mayr', UserRole::Foreman),
            'd.gruber' => $this->user($manager, 'd.gruber', 'David', 'Gruber', UserRole::Worker),
        ];

        $now = new \DateTimeImmutable();
        $monday = new \DateTimeImmutable('monday this week');
        $running = [];

        foreach (self::CURRENT_WEEK as [$day, $time, $plannedHours, $actualHours, $worker, $title, $customer, $priority]) {
            $start = $monday->modify(sprintf('+%d days %s', $day, $time));
            $job = $this->job($manager, $users[$worker], $title, $customer, $priority, $start, (int) ($plannedHours * 60));
            $actualEnd = $start->modify(sprintf('+%d minutes', (int) ($actualHours * 60)));

            if ($actualEnd <= $now) {
                $this->track($manager, $job, $start, $actualEnd)->getJob()->complete($actualEnd);
            } elseif ($start <= $now && !isset($running[$worker])) {
                // Wird gerade bearbeitet – die Zeiterfassung laeuft noch.
                $manager->persist(new TimeEntry($users[$worker], $job, $start));
                $job->markInProgress();
                $running[$worker] = true;
            }
        }

        $this->multiDay($manager, $users['b.steiner'], $monday, $now);
        $this->backlog($manager, $users);
        $this->history($manager, $users, $monday);

        // F7 – zwei offene "Brauche Arbeit"-Anfragen.
        $nextWorkday = $this->nextWorkday($now);
        $manager->persist(new WorkRequest($users['b.steiner'], $nextWorkday->setTime(7, 0), $now));
        $manager->persist(new WorkRequest($users['d.gruber'], $this->nextWorkday($nextWorkday)->setTime(13, 0), $now));

        $manager->flush();
    }

    /**
     * Mehrtaegige Arbeit (Mi 07:00 bis Do 15:00 geplant). Gearbeitet wird in
     * Abschnitten – ueber Nacht laeuft keine Zeit.
     */
    private function multiDay(ObjectManager $manager, User $worker, \DateTimeImmutable $monday, \DateTimeImmutable $now): void
    {
        $start = $monday->modify('+2 days 07:00');
        $job = (new Job())
            ->setTitle('Mähdrescher Generalüberholung')
            ->setDescription("Schneidwerk, Dreschtrommel und Hydraulik prüfen.\nErsatzteile liegen im Lager, Regal 4.")
            ->setCustomer('Gut Ebersdorf')
            ->setPriority(Priority::High)
            ->setAssignee($worker)
            ->setPlannedMinutes(12 * 60)
            ->schedule($start, null, $monday->modify('+3 days 15:00'));
        $manager->persist($job);

        $sections = [['+2 days 07:00', '+2 days 11:30'], ['+2 days 12:00', '+2 days 15:30'], ['+3 days 07:00', '+3 days 10:00']];
        foreach ($sections as [$from, $to]) {
            $from = $monday->modify($from);
            $to = $monday->modify($to);
            if ($to <= $now) {
                $manager->persist((new TimeEntry($worker, $job, $from))->stop($to));
                $job->markInProgress();
            }
        }
    }

    /**
     * Aufgaben ohne Termin – stehen nur in der Aufgabenverwaltung, nicht im Kalender.
     *
     * @param array<string, User> $users
     */
    private function backlog(ObjectManager $manager, array $users): void
    {
        $tasks = [
            ['Hebebühne 2 warten lassen', 'Wartungsfirma anrufen, Termin vereinbaren.', null, Priority::Medium, null],
            ['Werkzeugwand neu beschriften', null, null, Priority::Low, 'd.gruber'],
            ['Frontlader Hydraulikleck', 'Kunde bringt den Traktor, sobald die Ernte vorbei ist.', 'Fam. Brunner', Priority::High, 'a.huber'],
            ['Ersatzteile Lagerinventur', 'Regale 1–6 zählen und Fehlbestände notieren.', null, Priority::Low, 'b.steiner'],
        ];

        foreach ($tasks as [$title, $description, $customer, $priority, $worker]) {
            $manager->persist((new Job())
                ->setTitle($title)
                ->setDescription($description)
                ->setCustomer($customer)
                ->setPriority($priority)
                ->setAssignee(null !== $worker ? $users[$worker] : null));
        }
    }

    /**
     * Drei Wochen erledigte Arbeiten mit realistischen Abweichungen.
     *
     * @param array<string, User> $users
     */
    private function history(ObjectManager $manager, array $users, \DateTimeImmutable $monday): void
    {
        mt_srand(40);
        $workers = array_filter($users, static fn (User $user): bool => $user->getRole()->worksInWorkshop());

        for ($week = 3; $week >= 1; --$week) {
            foreach ($workers as $user) {
                for ($day = 0; $day < 5; ++$day) {
                    $start = $monday->modify(sprintf('-%d days 07:00', 7 * $week - $day));

                    foreach ([4, 3.5] as $plannedHours) {
                        $planned = (int) ($plannedHours * 60);
                        $actual = $planned + 15 * mt_rand(-3, 5);
                        $title = self::HISTORY_TITLES[mt_rand(0, \count(self::HISTORY_TITLES) - 1)];

                        $job = $this->job($manager, $user, $title, 'Werkstattauftrag', Priority::Medium, $start, $planned);
                        $end = $start->modify(sprintf('+%d minutes', $actual));
                        $this->track($manager, $job, $start, $end);
                        $job->complete($end);

                        $start = $start->modify(sprintf('+%d minutes', $planned + 30));
                    }
                }
            }
        }
    }

    private function user(ObjectManager $manager, string $username, string $firstName, string $lastName, UserRole $role): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRole($role);
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $manager->persist($user);

        return $user;
    }

    private function job(ObjectManager $manager, User $assignee, string $title, string $customer, Priority $priority, \DateTimeImmutable $start, int $plannedMinutes): Job
    {
        $job = (new Job())
            ->setTitle($title)
            ->setCustomer($customer)
            ->setPriority($priority)
            ->setAssignee($assignee)
            ->schedule($start, $plannedMinutes);
        $manager->persist($job);

        return $job;
    }

    private function track(ObjectManager $manager, Job $job, \DateTimeImmutable $start, \DateTimeImmutable $end): TimeEntry
    {
        \assert(null !== $job->getAssignee());

        $entry = (new TimeEntry($job->getAssignee(), $job, $start))->stop($end);
        $manager->persist($entry);

        return $entry;
    }

    private function nextWorkday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $next = $day->modify('+1 day');

        return (int) $next->format('N') >= 6 ? $next->modify('next monday') : $next;
    }
}
