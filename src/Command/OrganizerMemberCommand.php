<?php

namespace App\Command;

use App\Entity\Organizer;
use App\Entity\User;
use App\Enum\OrganizerRole;
use App\Repository\OrganizerRepository;
use App\Repository\UserRepository;
use App\Service\Organizer\OrganizerMembershipService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gestion des appartenances en ligne de commande.
 *
 * Indispensable à l'amorçage : tant qu'une organisation n'a aucun membre,
 * personne n'a le droit d'en ajouter via l'API (le contrôle d'accès s'appuie
 * justement sur l'appartenance). C'est aussi le moyen le plus direct pour le
 * fondateur de créer les premières équipes.
 */
#[AsCommand(
    name: 'app:organizer:member',
    description: 'Gère les membres d’une organisation (list, assign, revoke, transfer, organizations)'
)]
final class OrganizerMemberCommand extends Command
{
    public function __construct(
        private readonly OrganizerMembershipService $membershipService,
        private readonly OrganizerRepository $organizers,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'list | assign | revoke | transfer | organizations')
            ->addOption('organizer', 'o', InputOption::VALUE_REQUIRED, 'Id ou nom exact de l’organisation')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Id ou email de l’utilisateur')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'owner | admin | member', 'member')
            ->addOption('keep-owners', null, InputOption::VALUE_NONE, 'transfer : conserver les responsables actuels en co-responsables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        try {
            return match ($action) {
                'list' => $this->listMembers($io, $input),
                'assign' => $this->assign($io, $input),
                'revoke' => $this->revoke($io, $input),
                'transfer' => $this->transfer($io, $input),
                'organizations' => $this->listOrganizations($io, $input),
                default => $this->unknownAction($io, $action),
            };
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function listMembers(SymfonyStyle $io, InputInterface $input): int
    {
        $organizer = $this->resolveOrganizer($input);
        $memberships = $this->membershipService->membersOf($organizer);

        $io->title(sprintf('Membres de « %s »', (string) $organizer->getName()));

        if ($memberships === []) {
            $io->warning('Aucun membre. Utilisez « assign --role=owner » pour désigner un responsable.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Id', 'Email', 'Nom', 'Rôle'],
            array_map(static fn ($m) => [
                $m->getUser()?->getId(),
                $m->getUser()?->getEmail(),
                trim(sprintf('%s %s', (string) $m->getUser()?->getFirstname(), (string) $m->getUser()?->getLastname())),
                $m->getRole()->label(),
            ], $memberships)
        );

        return Command::SUCCESS;
    }

    private function listOrganizations(SymfonyStyle $io, InputInterface $input): int
    {
        $user = $this->resolveUser($input);
        $memberships = $this->membershipService->membershipsOf($user);

        $io->title(sprintf('Organisations de %s', (string) $user->getEmail()));

        if ($user->isSuperAdmin()) {
            $io->note('Cet utilisateur est super administrateur : il a accès à toutes les organisations.');
        }

        if ($memberships === []) {
            $io->warning('Aucune organisation.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Id', 'Organisation', 'Rôle'],
            array_map(static fn ($m) => [
                $m->getOrganizer()?->getId(),
                $m->getOrganizer()?->getName(),
                $m->getRole()->label(),
            ], $memberships)
        );

        return Command::SUCCESS;
    }

    private function assign(SymfonyStyle $io, InputInterface $input): int
    {
        $organizer = $this->resolveOrganizer($input);
        $user = $this->resolveUser($input);
        $role = OrganizerRole::fromInput((string) $input->getOption('role'));

        $membership = $this->membershipService->assign($user, $organizer, $role);

        $io->success(sprintf(
            '%s est %s de « %s ».',
            (string) $user->getEmail(),
            strtolower($membership->getRole()->label()),
            (string) $organizer->getName()
        ));

        return Command::SUCCESS;
    }

    private function revoke(SymfonyStyle $io, InputInterface $input): int
    {
        $organizer = $this->resolveOrganizer($input);
        $user = $this->resolveUser($input);

        $this->membershipService->revoke($user, $organizer);

        $io->success(sprintf(
            '%s a été retiré de « %s ».',
            (string) $user->getEmail(),
            (string) $organizer->getName()
        ));

        return Command::SUCCESS;
    }

    private function transfer(SymfonyStyle $io, InputInterface $input): int
    {
        $organizer = $this->resolveOrganizer($input);
        $user = $this->resolveUser($input);

        $this->membershipService->transferOwnership(
            $organizer,
            $user,
            $input->getOption('keep-owners') ? null : OrganizerRole::ADMIN
        );

        $io->success(sprintf(
            '%s est désormais responsable de « %s ».',
            (string) $user->getEmail(),
            (string) $organizer->getName()
        ));

        return $this->listMembers($io, $input);
    }

    private function unknownAction(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf(
            'Action « %s » inconnue. Actions disponibles : list, assign, revoke, transfer, organizations.',
            $action
        ));

        return Command::INVALID;
    }

    private function resolveOrganizer(InputInterface $input): Organizer
    {
        $reference = (string) $input->getOption('organizer');

        if ($reference === '') {
            throw new \InvalidArgumentException('Renseignez --organizer (id ou nom exact).');
        }

        $organizer = ctype_digit($reference)
            ? $this->organizers->find((int) $reference)
            : $this->organizers->findOneBy(['name' => $reference]);

        if ($organizer === null) {
            throw new \InvalidArgumentException(sprintf('Organisation introuvable : %s', $reference));
        }

        return $organizer;
    }

    private function resolveUser(InputInterface $input): User
    {
        $reference = (string) $input->getOption('user');

        if ($reference === '') {
            throw new \InvalidArgumentException('Renseignez --user (id ou email).');
        }

        $user = ctype_digit($reference)
            ? $this->users->find((int) $reference)
            : $this->users->findOneBy(['email' => strtolower(trim($reference))]);

        if ($user === null) {
            throw new \InvalidArgumentException(sprintf('Utilisateur introuvable : %s', $reference));
        }

        return $user;
    }
}
