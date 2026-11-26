<?php

namespace App\Command;

use App\Service\Storage\FileStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vérifie la connexion au stockage S3 (MinIO) : écriture, lecture,
 * URL présignée puis suppression d'un fichier témoin.
 *
 *   php bin/console app:storage:check
 */
#[AsCommand(name: 'app:storage:check', description: 'Vérifie la connexion au stockage de fichiers (MinIO / S3)')]
final class StorageCheckCommand extends Command
{
    public function __construct(private readonly FileStorage $storage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = sprintf('_healthcheck/%s.txt', bin2hex(random_bytes(6)));
        $payload = 'ticketUp storage check '.date(DATE_ATOM);

        try {
            $this->storage->write($key, $payload, 'text/plain');
            $io->writeln("✔ Écriture  : <info>$key</info>");

            if ($this->storage->read($key) !== $payload) {
                throw new \RuntimeException('Le contenu relu ne correspond pas au contenu écrit.');
            }
            $io->writeln('✔ Lecture   : contenu identique');

            $io->writeln('✔ URL présignée (60 s) : '.$this->storage->temporaryUrl($key, 60));
        } catch (\Throwable $e) {
            $io->error(['Stockage inaccessible : '.$e->getMessage(), 'Vérifier que MinIO tourne (../outils) et les variables S3_* de .env.local.']);

            return Command::FAILURE;
        } finally {
            try {
                $this->storage->delete($key);
            } catch (\Throwable) {
            }
        }

        $io->writeln('✔ Suppression');
        $io->success('Stockage S3 opérationnel.');

        return Command::SUCCESS;
    }
}
