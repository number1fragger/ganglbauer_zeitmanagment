<?php

namespace App\Command;

use App\Entity\User;
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
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Vergibt zusaetzlich ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = new User();
        $user->setEmail((string) $input->getArgument('email'));
        $user->setFirstName((string) $input->getArgument('firstName'));
        $user->setLastName((string) $input->getArgument('lastName'));
        $user->setPassword($this->hasher->hashPassword($user, (string) $input->getArgument('password')));

        if ($input->getOption('admin')) {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Benutzer "%s" wurde angelegt.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
