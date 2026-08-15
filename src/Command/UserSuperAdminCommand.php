<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Attribue ou retire le rôle global de super administrateur (fondateur du site).
 *
 * Ce rôle est volontairement hors de l'API : il donne accès à toutes les
 * organisations, il ne doit pas pouvoir être accordé depuis l'interface.
 */
#[AsCommand(
    name: 'app:user:super-admin',
    description: 'Donne (ou retire avec --revoke) le rôle ROLE_SUPER_ADMIN à un utilisateur'
)]
final class UserSuperAdminCommand extends Command
{
    public function __construct(private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email de l’utilisateur')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Retire le rôle au lieu de l’attribuer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));

        $user = $this->users->findOneBy(['email' => $email]);

        if ($user === null) {
            $io->error(sprintf('Utilisateur introuvable : %s', $email));

            return Command::FAILURE;
        }

        $roles = $user->getRoles();
        $revoke = (bool) $input->getOption('revoke');

        // `getRoles()` ajoute ROLE_USER : on le retire pour ne pas le figer en base.
        $roles = array_values(array_diff($roles, ['ROLE_USER']));

        if ($revoke) {
            $roles = array_values(array_diff($roles, [User::ROLE_SUPER_ADMIN]));
        } elseif (!in_array(User::ROLE_SUPER_ADMIN, $roles, true)) {
            $roles[] = User::ROLE_SUPER_ADMIN;
        }

        $user->setRoles($roles);
        $this->users->saveUser($user);

        $io->success(sprintf(
            '%s %s super administrateur.',
            $email,
            $revoke ? 'n’est plus' : 'est désormais'
        ));

        return Command::SUCCESS;
    }
}
