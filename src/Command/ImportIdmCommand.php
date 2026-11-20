<?php

namespace App\Command;

use App\Service\IdmMigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:idm:import',
    description: 'Import User, Clan and Gamer_Clan CSV exports and create the Cognito user CSV',
)]
final class ImportIdmCommand extends Command
{
    public function __construct(private readonly IdmMigrationService $migration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('users', InputArgument::REQUIRED, 'CSV export of the IDM User table')
            ->addArgument('clans', InputArgument::REQUIRED, 'CSV export of the IDM Clan table')
            ->addArgument('memberships', InputArgument::REQUIRED, 'CSV export of the IDM Gamer_Clan table')
            ->addArgument('cognito-output', InputArgument::REQUIRED, 'Path for the generated Cognito CSV')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and roll back the local database import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $result = $this->migration->import(
                (string) $input->getArgument('users'),
                (string) $input->getArgument('clans'),
                (string) $input->getArgument('memberships'),
                (string) $input->getArgument('cognito-output'),
                $dryRun,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s %d users, %d clans and %d memberships; Cognito CSV: %s',
            $dryRun ? 'Validated' : 'Imported',
            $result['users'],
            $result['clans'],
            $result['memberships'],
            (string) $input->getArgument('cognito-output'),
        ));

        return Command::SUCCESS;
    }
}
