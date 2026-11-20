<?php

namespace App\Command;

use App\Entity\User;
use App\Idm\IdmManager;
use App\Service\ApiTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:api-token:create', description: 'Create a bearer token for an IDM user')]
class CreateApiTokenCommand extends Command
{
    public function __construct(
        private readonly IdmManager $idmManager,
        private readonly ApiTokenService $apiTokenService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the IDM user')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name for this token', 'MCP client')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Token lifetime in days', '90');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $user = $this->idmManager->getRepository(User::class)->findOneCiBy(['email' => $email]);
        if (!$user instanceof User || null === $user->getUuid()) {
            $io->error(sprintf('No IDM user found for "%s".', $email));

            return Command::FAILURE;
        }

        $expiresInDays = filter_var($input->getOption('expires'), FILTER_VALIDATE_INT);
        if (false === $expiresInDays || $expiresInDays < 1) {
            $io->error('The --expires value must be a positive number of days.');

            return Command::FAILURE;
        }

        try {
            $token = $this->apiTokenService->createToken(
                $user->getUuid(),
                (string) $input->getOption('name'),
                $expiresInDays,
            );
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Token created. Copy it now; it cannot be displayed again.');
        $io->writeln($token);

        return Command::SUCCESS;
    }
}
