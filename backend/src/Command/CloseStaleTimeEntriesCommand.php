<?php

namespace App\Command;

use App\Service\TimeTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Beendet vergessene Arbeitsabschnitte an der Kappungsgrenze (siehe
 * TimeEntry::autoCloseAt()). Das passiert ohnehin beim naechsten Klick des
 * Arbeiters; als naechtlicher Cronjob haelt es die Daten auch sonst sauber:
 *
 *     15 21 * * *  php bin/console app:time:close-stale
 */
#[AsCommand(name: 'app:time:close-stale', description: 'Beendet vergessene, noch laufende Arbeitsabschnitte')]
final class CloseStaleTimeEntriesCommand extends Command
{
    public function __construct(private readonly TimeTracker $tracker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('%d Arbeitsabschnitt(e) beendet.', $this->tracker->closeStale()));

        return Command::SUCCESS;
    }
}
