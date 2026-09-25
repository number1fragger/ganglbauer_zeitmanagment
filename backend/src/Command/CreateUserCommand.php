<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-user', description: 'Legt eine Benutzerin oder einen Benutzer an.')]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse')
            ->addArgument('password', InputArgument::REQUIRED, 'Passwort')
            ->addArgument('firstName', InputArgument::OPTIONAL, 'Vorname', 'Max')
            ->addArgument('lastName', InputArgument::OPTIONAL, 'Nachname', 'Mustermann')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Kurzform fuer --role=chef')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'chef, vorarbeiter oder arbeiter', 'arbeiter');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = new User();
        $user->setEmail((string) $input->getArgument('email'));
        $user->setFirstName((string) $input->getArgument('firstName'));
        $user->setLastName((string) $input->getArgument('lastName'));
        $user->setPassword($this->hasher->hashPassword($user, (string) $input->getArgument('password')));

        $role = $input->getOption('admin') ? 'chef' : (string) $input->getOption('role');
        $user->setRole(match ($role) {
            'chef' => UserRole::Admin,
            'vorarbeiter' => UserRole::Foreman,
            'arbeiter' => UserRole::Worker,
            default => throw new \InvalidArgumentException('Rolle muss chef, vorarbeiter oder arbeiter sein.'),
        });

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Benutzer "%s" wurde als %s angelegt.', $user->getEmail(), $user->getRoleLabel()));

        return Command::SUCCESS;
    }
}
