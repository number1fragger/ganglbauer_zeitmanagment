<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\WorkRequest;
use App\Enum\WorkRequestStatus;
use App\Repository\UserRepository;
use App\Repository\WorkRequestRepository;

/**
 * Statistik "Wie oft fragen Mitarbeiter Arbeit an?"
 *
 * Zaehlregel: Eine Arbeitsanfrage ist eine ueber "Brauche Arbeit"
 * abgeschickte Anfrage (ein Datensatz in work_request), gezaehlt nach dem
 * Zeitpunkt, an dem sie gestellt wurde. Ansehen, Neuladen oder das Aendern
 * einer noch offenen Anfrage erzeugen keinen neuen Datensatz und zaehlen
 * daher nicht erneut. Zurueckgezogene Anfragen zaehlen nicht mit (sie werden
 * nur zur Information getrennt ausgewiesen) – damit zaehlen auch aeltere
 * Datensaetze richtig, bei denen eine geaenderte Anfrage frueher als neue
 * Anfrage gespeichert und die alte zurueckgezogen wurde.
 *
 * Es werden nur gespeicherte Anfragen ausgewertet – nichts geschaetzt.
 */
class WorkRequestReport
{
    public function __construct(
        private readonly WorkRequestRepository $requests,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = [];

        // Alle aktiven Mitarbeiter erscheinen – auch mit 0 Anfragen, fuer den Vergleich.
        foreach ($this->users->findWorkers() as $worker) {
            $rows['u'.$worker->getId()] = $this->row($worker->getId(), $worker->getFullName(), $worker);
        }

        foreach ($this->requests->findCreatedBetween($from, $to) as $request) {
            $user = $request->getUser();
            $key = null !== $user ? 'u'.$user->getId() : 'n'.$request->getUserName();
            $rows[$key] ??= $this->row($user?->getId(), $request->getUserName(), $user);
            $this->count($rows[$key], $request);
        }

        $previous = $this->countIn($from->sub($from->diff($to)), $from);

        $perWorker = array_values($rows);
        usort($perWorker, static fn (array $a, array $b): int => $b['requests'] <=> $a['requests'] ?: strcmp($a['worker'], $b['worker']));

        $total = array_sum(array_column($perWorker, 'requests'));
        $withRequests = \count(array_filter($perWorker, static fn (array $row): bool => $row['requests'] > 0));
        foreach ($perWorker as &$row) {
            $row['share'] = $total > 0 ? round($row['requests'] / $total * 100, 1) : 0.0;
        }
        unset($row);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'rule' => 'Gezählt wird jede abgeschickte „Brauche Arbeit“-Anfrage einmal, nach dem Zeitpunkt der Anfrage. Ändern einer offenen Anfrage oder Neuladen zählt nicht erneut; zurückgezogene Anfragen zählen nicht.',
            'totals' => [
                'requests' => $total,
                'previousRequests' => $previous,
                'fulfilled' => array_sum(array_column($perWorker, 'fulfilled')),
                'open' => array_sum(array_column($perWorker, 'open')),
                'withdrawn' => array_sum(array_column($perWorker, 'withdrawn')),
                'workersWithRequests' => $withRequests,
                'averagePerWorker' => \count($perWorker) > 0 ? round($total / \count($perWorker), 1) : 0.0,
            ],
            'perWorker' => $perWorker,
        ];
    }

    /**
     * @return array{workerId: int|null, worker: string, role: string|null, deleted: bool, active: bool, requests: int, fulfilled: int, open: int, withdrawn: int}
     */
    private function row(?int $id, string $name, ?User $user): array
    {
        return [
            'workerId' => $id,
            'worker' => $name,
            'role' => $user?->getRole()->value,
            'deleted' => null === $user,
            'active' => $user?->isActive() ?? false,
            'requests' => 0,
            'fulfilled' => 0,
            'open' => 0,
            'withdrawn' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function count(array &$row, WorkRequest $request): void
    {
        match ($request->getStatus()) {
            WorkRequestStatus::Withdrawn => ++$row['withdrawn'],
            WorkRequestStatus::Fulfilled => ++$row['fulfilled'],
            WorkRequestStatus::Open => ++$row['open'],
        };

        if (WorkRequestStatus::Withdrawn !== $request->getStatus()) {
            ++$row['requests'];
        }
    }

    private function countIn(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return \count(array_filter(
            $this->requests->findCreatedBetween($from, $to),
            static fn (WorkRequest $request): bool => WorkRequestStatus::Withdrawn !== $request->getStatus(),
        ));
    }
}
