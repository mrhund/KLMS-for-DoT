<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByUuid(UuidInterface|string $uuid): ?User
    {
        return $this->find($uuid);
    }

    /**
     * @param array<UuidInterface|string> $uuids
     * @return User[]
     */
    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $users = $this->createQueryBuilder('u')
            ->andWhere('u.uuid IN (:uuids)')
            ->setParameter('uuids', array_map('strval', $uuids))
            ->getQuery()
            ->getResult();

        $byUuid = [];
        foreach ($users as $user) {
            $byUuid[$user->getUuid()->toString()] = $user;
        }

        return array_values(array_filter(array_map(
            static fn (UuidInterface|string $uuid): ?User => $byUuid[(string) $uuid] ?? null,
            $uuids,
        )));
    }

    public function findOneByEmailInsensitive(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', strtolower($email))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByNicknameInsensitive(string $nickname): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.nickname) = :nickname')
            ->setParameter('nickname', strtolower($nickname))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return User[]
     */
    public function search(string $query, ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.nickname', 'ASC')
            ->setFirstResult($offset);

        if ($query !== '') {
            $qb->andWhere(
                'LOWER(u.nickname) LIKE :query OR LOWER(u.firstname) LIKE :query OR LOWER(u.surname) LIKE :query OR LOWER(u.email) LIKE :query',
            )->setParameter('query', '%'.strtolower($query).'%');
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function countSearch(string $query): int
    {
        $qb = $this->createQueryBuilder('u')->select('COUNT(u.uuid)');
        if ($query !== '') {
            $qb->andWhere(
                'LOWER(u.nickname) LIKE :query OR LOWER(u.firstname) LIKE :query OR LOWER(u.surname) LIKE :query OR LOWER(u.email) LIKE :query',
            )->setParameter('query', '%'.strtolower($query).'%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
