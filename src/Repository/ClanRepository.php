<?php

namespace App\Repository;

use App\Entity\Clan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

class ClanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Clan::class);
    }

    public function findOneByUuid(UuidInterface|string $uuid): ?Clan
    {
        return $this->find($uuid);
    }

    /**
     * @param array<UuidInterface|string> $uuids
     * @return Clan[]
     */
    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $clans = $this->createQueryBuilder('c')
            ->andWhere('c.uuid IN (:uuids)')
            ->setParameter('uuids', array_map('strval', $uuids))
            ->getQuery()
            ->getResult();

        $byUuid = [];
        foreach ($clans as $clan) {
            $byUuid[$clan->getUuid()->toString()] = $clan;
        }

        return array_values(array_filter(array_map(
            static fn (UuidInterface|string $uuid): ?Clan => $byUuid[(string) $uuid] ?? null,
            $uuids,
        )));
    }

    /**
     * @return Clan[]
     */
    public function search(string $query, ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->setFirstResult($offset);

        if ($query !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.clantag) LIKE :query')
                ->setParameter('query', '%'.strtolower($query).'%');
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function countSearch(string $query): int
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c.uuid)');
        if ($query !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.clantag) LIKE :query')
                ->setParameter('query', '%'.strtolower($query).'%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
