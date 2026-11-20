<?php

namespace App\Command;

use App\Service\ApiTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:api-token:revoke', description: 'Revoke a bearer token by its database ID')]
class RevokeApiTokenCommand extends Command
{
    public function __construct(private readonly ApiTokenService $apiTokenService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Database ID of the token to revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = filter_var($input->getArgument('id'), FILTER_VALIDATE_INT);
        if (false === $id || $id < 1) {
            $io->error('The token ID must be a positive integer.');

            return Command::FAILURE;
        }

        if (!$this->apiTokenService->revokeToken($id)) {
            $io->error(sprintf('Token with ID %d was not found.', $id));

            return Command::FAILURE;
        }

        $io->success(sprintf('Token %d revoked.', $id));

        return Command::SUCCESS;
    }
}
