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
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Legt den ersten Chef am Server an, z. B.:
 *   php bin/console app:create-user p.hofer Peter Hofer --role=chef
 */
#[AsCommand(name: 'app:create-user', description: 'Legt eine Benutzerin oder einen Benutzer an.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Benutzername, z. B. p.hofer')
            ->addArgument('firstName', InputArgument::REQUIRED, 'Vorname')
            ->addArgument('lastName', InputArgument::REQUIRED, 'Nachname')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'chef, vorarbeiter oder arbeiter', UserRole::Worker->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $role = UserRole::tryFrom((string) $input->getOption('role'));
        if (null === $role) {
            $io->error('Unbekannte Rolle. Erlaubt: chef, vorarbeiter, arbeiter.');

            return Command::INVALID;
        }

        $password = (string) $io->askHidden('Passwort (mindestens 8 Zeichen)');
        if (mb_strlen($password) < 8) {
            $io->error('Das Passwort braucht mindestens 8 Zeichen.');

            return Command::INVALID;
        }

        $user = (new User())
            ->setUsername((string) $input->getArgument('username'))
            ->setFirstName((string) $input->getArgument('firstName'))
            ->setLastName((string) $input->getArgument('lastName'))
            ->setRole($role);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().': '.$violation->getMessage());
            }

            return Command::FAILURE;
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('"%s" wurde als %s angelegt.', $user->getUsername(), $role->value));

        return Command::SUCCESS;
    }
}
