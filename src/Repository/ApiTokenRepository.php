<?php

namespace App\Repository;

use App\Entity\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findBySelector(string $selector): ?ApiToken
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    /**
     * @return ApiToken[]
     */
    public function findForUser(?UuidInterface $userUuid = null): array
    {
        $criteria = null === $userUuid ? [] : ['userUuid' => $userUuid];

        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }
}
