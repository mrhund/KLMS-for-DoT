<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:api-token:list', description: 'List bearer tokens without revealing their secrets')]
class ListApiTokensCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ApiTokenService $apiTokenService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'Only list tokens belonging to this user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userUuid = null;
        $email = $input->getArgument('email');
        if (null !== $email) {
            $user = $this->userRepository->findOneByEmailInsensitive((string) $email);
            if (!$user instanceof User || null === $user->getUuid()) {
                $io->error(sprintf('No user found for "%s".', $email));

                return Command::FAILURE;
            }
            $userUuid = $user->getUuid();
        }

        $rows = [];
        foreach ($this->apiTokenService->listTokens($userUuid) as $token) {
            $rows[] = [
                $token->getId(),
                (string) $token->getUserUuid(),
                $token->getName(),
                $token->getSelector(),
                $token->getCreatedAt()?->format(DATE_ATOM),
                $token->getExpiresAt()?->format(DATE_ATOM),
                $token->getLastUsedAt()?->format(DATE_ATOM) ?? '-',
                $token->isRevoked() ? $token->getRevokedAt()?->format(DATE_ATOM) : '-',
            ];
        }

        $io->table(['ID', 'User UUID', 'Name', 'Selector', 'Created', 'Expires', 'Last used', 'Revoked'], $rows);

        return Command::SUCCESS;
    }
}
