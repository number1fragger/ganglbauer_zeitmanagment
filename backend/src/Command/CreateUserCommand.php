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
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'chef, vorarbeiter oder arbeiter', 'arbeiter')
            ->addOption('skip-if-exists', null, InputOption::VALUE_NONE, 'Nichts tun, wenn es die E-Mail-Adresse schon gibt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        // Fuer den Container-Start: den ersten Chef nur beim allerersten Mal anlegen.
        if ($input->getOption('skip-if-exists') && null !== $this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            $io->note(sprintf('Benutzer "%s" gibt es schon – nichts zu tun.', $email));

            return Command::SUCCESS;
        }

        $user = new User();
        $user->setEmail($email);
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
